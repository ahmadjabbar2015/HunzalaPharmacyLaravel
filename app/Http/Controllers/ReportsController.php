<?php

namespace App\Http\Controllers;

use App\Models\DrawerSession;
use App\Services\ReportService;
use App\Support\BusinessDate;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * The reports the owner and managers can run.
 *
 * Every action is a GET and nothing here writes. The whole controller sits
 * behind can:view-reports (routes/web.php) rather than checking per action:
 * there is no report a cashier may see and another they may not, so one gate on
 * the group is both the truth and the thing nobody can forget to repeat.
 *
 * Each report has a screen and a CSV twin. The pair share the service call
 * exactly - the CSV is the same numbers flattened, never a second query that
 * could disagree with what was on screen.
 */
class ReportsController extends Controller
{
    public function __construct(private ReportService $reports) {}

    /** The hub: pick a report. */
    public function index(): View
    {
        return view('reports.index', [
            'today' => BusinessDate::today(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Daily sales
    // -----------------------------------------------------------------------

    public function dailySales(Request $request): View
    {
        return view('reports.daily-sales', [
            'report' => $this->reports->dailySales($this->dateFrom($request)),
        ]);
    }

    public function dailySalesCsv(Request $request): StreamedResponse
    {
        $date = $this->dateFrom($request);
        $report = $this->reports->dailySales($date);

        $rows = [];

        foreach ($report['sales'] as $sale) {
            $rows[] = [
                $sale['invoice_number'],
                $sale['sale_time']?->format('H:i'),
                $sale['staff_name'],
                $sale['customer_name'],
                $sale['payment_method'],
                $sale['subtotal'],
                $sale['discount'],
                $sale['net'],
                $sale['is_returned'] ? 'yes' : 'no',
            ];
        }

        /*
         * A totals row at the foot, not a separate file. The person opening
         * this is reconciling against a number they already have written down,
         * and making them build a SUM() in Excel to compare is how the two get
         * compared wrongly.
         */
        $rows[] = [];
        $rows[] = [
            'TOTAL', '', '', '', $report['total_count'],
            $report['total_subtotal'], $report['total_discount'], $report['total_net'], '',
        ];
        $rows[] = [
            'RETURNS', '', '', '', $report['return_count'], '', '', $report['return_total'], '',
        ];

        return Csv::download("daily-sales-{$date}.csv", [
            'Invoice', 'Time', 'Staff', 'Customer', 'Payment', 'Subtotal', 'Discount', 'Net', 'Returned',
        ], $rows);
    }

    // -----------------------------------------------------------------------
    // Drawer sessions
    // -----------------------------------------------------------------------

    /** Session history, newest first - the way in to a single summary. */
    public function sessions(): View
    {
        return view('reports.sessions', [
            'sessions' => DrawerSession::query()
                ->with(['openedBy', 'closedBy'])
                ->orderByDesc('session_date')
                ->orderByDesc('opened_at')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function sessionSummary(DrawerSession $session): View
    {
        return view('reports.session-summary', [
            'report' => $this->reports->sessionSummary($session->uuid),
        ]);
    }

    public function sessionSummaryCsv(DrawerSession $session): StreamedResponse
    {
        $report = $this->reports->sessionSummary($session->uuid);

        // A session summary is a set of facts rather than a table, so it
        // exports as label/value pairs. Forcing it into columns would produce a
        // one-row file nobody can read across.
        $rows = [
            ['Date', $report['session_date']?->format('Y-m-d')],
            ['Status', $report['status']],
            ['Opened by', $report['opened_by']],
            ['Opened at', $report['opened_at']?->format('Y-m-d H:i')],
            ['Opening float', $report['opening_float']],
            ['Closed by', $report['closed_by'] ?? ''],
            ['Closed at', $report['closed_at']?->format('Y-m-d H:i') ?? ''],
            [],
            ['Sales', $report['sale_count']],
            ['Cash sales', $report['cash_sales']],
            ['Mobile sales', $report['mobile_sales']],
            ['Total takings', $report['total_net']],
            ['Returns', $report['return_count']],
            ['Returns value', $report['return_total']],
            [],
            ['Expected cash', $report['expected_cash'] ?? 'not closed'],
            ['Counted cash', $report['closing_cash_counted'] ?? 'not closed'],
            ['Variance', $report['cash_variance'] ?? 'not closed'],
            [],
            ['Staff', 'Sales', 'Takings'],
        ];

        foreach ($report['by_staff'] as $name => $totals) {
            $rows[] = [$name, $totals['count'], $totals['net']];
        }

        $rows[] = [];
        $rows[] = ['Medicine', 'Quantity', 'Value'];

        foreach ($report['by_medicine'] as $name => $totals) {
            $rows[] = [$name, $totals['qty'], $totals['net']];
        }

        $date = $report['session_date']?->format('Y-m-d') ?? 'session';

        return Csv::download("session-{$date}.csv", ['Field', 'Value', ''], $rows);
    }

    // -----------------------------------------------------------------------
    // Inventory valuation
    // -----------------------------------------------------------------------

    public function inventory(): View
    {
        return view('reports.inventory', [
            'report' => $this->reports->inventory(),
        ]);
    }

    public function inventoryCsv(): StreamedResponse
    {
        $report = $this->reports->inventory();

        /*
         * One row per BATCH, with the item's code and name repeated, rather
         * than one row per item with the batches crammed into a cell. This is
         * the file an expiry check or a recall is run from, and both of those
         * need to filter and sort by batch.
         */
        $rows = [];

        foreach ($report['items'] as $item) {
            if ($item['batches'] === []) {
                $rows[] = [
                    $item['item_code'], $item['item_name'], $item['manufacturer'], $item['unit'],
                    $item['derived_qty'], $item['reorder_level'], $item['is_low_stock'] ? 'yes' : 'no',
                    $item['purchase_price'], $item['sales_price'], $item['stock_value'],
                    '', '', '', '',
                ];

                continue;
            }

            foreach ($item['batches'] as $batch) {
                $rows[] = [
                    $item['item_code'], $item['item_name'], $item['manufacturer'], $item['unit'],
                    $item['derived_qty'], $item['reorder_level'], $item['is_low_stock'] ? 'yes' : 'no',
                    $item['purchase_price'], $item['sales_price'], $item['stock_value'],
                    $batch['batch_number'],
                    $batch['expiry_date']?->format('Y-m-d'),
                    $batch['qty'],
                    $batch['is_expired'] ? 'expired' : ($batch['is_expiring'] ? 'expiring' : 'ok'),
                ];
            }
        }

        $rows[] = [];
        $rows[] = [
            'TOTAL', "{$report['total_lines']} items", '', '', '', '', '', '', '',
            $report['total_value'], '', '', '', '',
        ];

        return Csv::download('inventory-'.BusinessDate::today().'.csv', [
            'Code', 'Item', 'Manufacturer', 'Unit', 'Stock', 'Reorder at', 'Low',
            'Cost', 'Price', 'Stock value',
            'Batch', 'Expires', 'Batch qty', 'Expiry status',
        ], $rows);
    }

    // -----------------------------------------------------------------------
    // Profit & loss
    // -----------------------------------------------------------------------

    public function profitLoss(Request $request): View
    {
        [$from, $to] = $this->rangeFrom($request);

        return view('reports.profit-loss', [
            'report' => $this->reports->profitLoss($from, $to),
        ]);
    }

    public function profitLossCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->rangeFrom($request);
        $report = $this->reports->profitLoss($from, $to);

        $rows = [];

        foreach ($report['rows'] as $row) {
            $rows[] = [
                $row['item_code'], $row['item_name'], $row['qty_sold'],
                $row['revenue'], $row['cogs'], $row['gross_profit'],
            ];
        }

        $rows[] = [];
        $rows[] = ['TOTAL', '', '', $report['total_revenue'], $report['total_cogs'], $report['total_gross_profit']];
        $rows[] = ['Discounts', '', '', $report['total_discount']];
        $rows[] = ['Returns', '', '', $report['total_returns']];
        $rows[] = ['Net revenue', '', '', $report['net_revenue']];

        return Csv::download("profit-loss-{$from}-to-{$to}.csv", [
            'Code', 'Item', 'Quantity sold', 'Revenue', 'Cost of goods', 'Gross profit',
        ], $rows);
    }

    // -----------------------------------------------------------------------
    // Input
    // -----------------------------------------------------------------------

    /**
     * The business date a report was asked for, defaulting to today.
     *
     * Today means the shop's today (Asia/Karachi), not the server's UTC date.
     * At 2am local the two disagree, and the report would silently be for
     * yesterday.
     */
    private function dateFrom(Request $request): string
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        return isset($validated['date'])
            ? BusinessDate::for($validated['date'])->toDateString()
            : BusinessDate::today();
    }

    /**
     * A date range, defaulting to the current month to date.
     *
     * @return array{string, string}
     */
    private function rangeFrom(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            // Refused here rather than in the service so the manager sees it
            // next to the field they got wrong.
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $today = BusinessDate::for();

        $from = isset($validated['from'])
            ? BusinessDate::for($validated['from'])->toDateString()
            : $today->startOfMonth()->toDateString();

        $to = isset($validated['to'])
            ? BusinessDate::for($validated['to'])->toDateString()
            : $today->toDateString();

        return [$from, $to];
    }
}
