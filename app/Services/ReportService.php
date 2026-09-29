<?php

namespace App\Services;

use App\Exceptions\ReportException;
use App\Models\DrawerSession;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/*
 * Every report the shop can run. Ported from shared/services/report_service.py.
 *
 * Read-only from end to end. Nothing here writes a row, adjusts a cache, or
 * touches the stock ledger - which is why the reports can be run on a whim
 * during trade without anyone worrying about what they might change.
 *
 * Two rules from LARAVEL_PLAN.md bear directly on this class:
 *
 *   RULE 2 - every quantity shown is summed from stock_transactions. The cache
 *            columns are never read. A report that quoted the cache would be
 *            the one place a drifted cache became a decision the owner acted on.
 *
 *   §5 trap 2 - these are the widest queries in the system: a valuation touches
 *            every item and every batch. Each report therefore runs a fixed
 *            number of queries regardless of how many rows it covers, and the
 *            tests assert that count. A per-row SUM() here is a report that
 *            times out on the 1 GB VPS after a year of trading.
 *
 * Every money figure in and out is a scale-2 string (§5 trap 1). Totals are
 * accumulated through Money, never with `+`.
 *
 * Return shapes are plain arrays rather than objects. A report is data on its
 * way to a table, a CSV, or a print view and never gains behaviour - the
 * Python's dataclasses were the same decision in a language that makes them
 * cheap.
 */
class ReportService
{
    public function __construct(private StockService $stock) {}

    // -----------------------------------------------------------------------
    // Daily sales
    // -----------------------------------------------------------------------

    /**
     * Everything sold on one business date, with staff, payment-method and
     * medicine breakdowns (spec §12.7).
     *
     * This is the report the owner reads at closing: what came in, who took it,
     * and how. The breakdowns are computed here rather than in the view because
     * the CSV export needs the same numbers.
     *
     * @param  string  $date  a business date, Y-m-d
     * @return array<string, mixed>
     */
    public function dailySales(string $date): array
    {
        /*
         * whereDate, not a plain equality on the string.
         *
         * sale_date is a DATE column, but Eloquent's `date` cast writes it back
         * through the model's datetime format, so the stored value can carry a
         * zero time component. MySQL coerces that away and SQLite does not -
         * which means a bare `where('sale_date', '2026-09-29')` matches every
         * row in production and none in the test suite. Comparing the date part
         * explicitly is the same answer on both.
         */
        $sales = Sale::query()
            ->with(['staffMember', 'customer'])
            ->whereDate('sale_date', $date)
            ->orderBy('sale_time')
            ->get();

        $report = [
            'date' => $date,
            'sales' => [],
            'by_staff' => [],
            'by_payment' => [],
            'by_medicine' => [],
            'total_count' => 0,
            'total_subtotal' => Money::ZERO,
            'total_discount' => Money::ZERO,
            'total_net' => Money::ZERO,
            'return_count' => 0,
            'return_total' => Money::ZERO,
        ];

        foreach ($sales as $sale) {
            $staffName = $sale->staffMember?->full_name ?? $sale->staff_member_uuid;
            $method = $sale->payment_method ?: 'cash';

            $row = [
                'uuid' => $sale->uuid,
                'invoice_number' => $sale->invoice_number,
                'sale_time' => $sale->sale_time,
                'staff_name' => $staffName,
                // An em dash, not an empty cell: a walk-in with no customer
                // record is the normal case, not missing data.
                'customer_name' => $sale->customer?->full_name ?? '—',
                'payment_method' => $method,
                'subtotal' => Money::format($sale->subtotal_amount),
                'discount' => Money::format($sale->discount_amount),
                'net' => Money::format($sale->net_amount),
                'is_returned' => (bool) $sale->is_returned,
            ];

            $report['sales'][] = $row;
            $report['total_count']++;
            $report['total_subtotal'] = Money::add($report['total_subtotal'], $row['subtotal']);
            $report['total_discount'] = Money::add($report['total_discount'], $row['discount']);
            $report['total_net'] = Money::add($report['total_net'], $row['net']);

            $report['by_staff'] = $this->accumulate($report['by_staff'], $staffName, 'count', $row['net']);
            $report['by_payment'] = $this->accumulate($report['by_payment'], $method, 'count', $row['net']);
        }

        $report['by_medicine'] = $this->medicineBreakdown($sales->modelKeys());

        // Returns recorded on the same date, as one aggregate. They are shown
        // beside the takings rather than netted off them: the owner needs to
        // see both numbers, because a day with heavy returns and a day with
        // few sales look identical once they are combined.
        $returns = SaleReturn::query()
            ->whereDate('return_date', $date)
            ->selectRaw('COUNT(*) as row_count, COALESCE(SUM(total_amount), 0) as total')
            ->first();

        $report['return_count'] = (int) ($returns->row_count ?? 0);
        $report['return_total'] = Money::format($returns->total ?? 0);

        return $report;
    }

    // -----------------------------------------------------------------------
    // Session summary
    // -----------------------------------------------------------------------

    /**
     * One drawer session end to end, including the cash reconciliation
     * (spec §5, §12.7).
     *
     * The variance is read from the session rather than recomputed. SessionService
     * calculated it at close from the float, the cash sales and the count, and
     * that figure is what the person closing signed for - a report that worked it
     * out again could disagree with the record and there would be no way to tell
     * which was right.
     *
     * @return array<string, mixed>
     *
     * @throws ReportException when no such session exists
     */
    public function sessionSummary(string $sessionUuid): array
    {
        $session = DrawerSession::query()
            ->with(['openedBy', 'closedBy'])
            ->whereKey($sessionUuid)
            ->first();

        if ($session === null) {
            throw new ReportException("Session {$sessionUuid} was not found.");
        }

        $report = [
            'session_uuid' => $session->uuid,
            'session_date' => $session->session_date,
            'opened_by' => $session->openedBy?->full_name ?? $session->opened_by_user_uuid,
            'opened_at' => $session->opened_at,
            'opening_float' => Money::format($session->opening_float),
            'closed_by' => $session->closed_by_user_uuid
                ? ($session->closedBy?->full_name ?? $session->closed_by_user_uuid)
                : null,
            'closed_at' => $session->closed_at,
            // These three stay null on an open session rather than becoming
            // 0.00. A drawer that has not been counted has no variance, and
            // showing a zero would read as "it balanced".
            'closing_cash_counted' => $session->closing_cash_counted === null
                ? null : Money::format($session->closing_cash_counted),
            'expected_cash' => $session->expected_cash === null
                ? null : Money::format($session->expected_cash),
            'cash_variance' => $session->cash_variance === null
                ? null : Money::format($session->cash_variance),
            'status' => $session->status,
            'sale_count' => 0,
            'cash_sales' => Money::ZERO,
            'mobile_sales' => Money::ZERO,
            'total_net' => Money::ZERO,
            'return_count' => 0,
            'return_total' => Money::ZERO,
            'by_staff' => [],
            'by_medicine' => [],
        ];

        $sales = Sale::query()
            ->with('staffMember')
            ->where('session_uuid', $sessionUuid)
            ->orderBy('sale_time')
            ->get();

        foreach ($sales as $sale) {
            $net = Money::format($sale->net_amount);
            $report['sale_count']++;
            $report['total_net'] = Money::add($report['total_net'], $net);

            // Anything that is not cash did not go into the drawer, so the two
            // are split here: only the cash figure has any bearing on the count.
            $bucket = ($sale->payment_method ?: 'cash') === 'cash' ? 'cash_sales' : 'mobile_sales';
            $report[$bucket] = Money::add($report[$bucket], $net);

            $staffName = $sale->staffMember?->full_name ?? $sale->staff_member_uuid;
            $report['by_staff'] = $this->accumulate($report['by_staff'], $staffName, 'count', $net);
        }

        $report['by_medicine'] = $this->medicineBreakdown($sales->modelKeys());

        $returns = SaleReturn::query()
            ->where('session_uuid', $sessionUuid)
            ->selectRaw('COUNT(*) as row_count, COALESCE(SUM(total_amount), 0) as total')
            ->first();

        $report['return_count'] = (int) ($returns->row_count ?? 0);
        $report['return_total'] = Money::format($returns->total ?? 0);

        return $report;
    }

    // -----------------------------------------------------------------------
    // Inventory valuation
    // -----------------------------------------------------------------------

    /**
     * Every active item with its derived stock, its live batches, expiry
     * status and valuation.
     *
     * Valued at purchase price, not sales price: this answers "what is sitting
     * on the shelves worth", which is a cost question. Valuing stock at what it
     * might sell for would book profit that has not happened.
     *
     * @return array<string, mixed>
     */
    public function inventory(): array
    {
        $alertDays = Setting::current()?->expiry_alert_days ?? 30;
        $today = BusinessDate::for();
        $cutoff = $today->addDays($alertDays);

        $items = Item::query()
            ->where('is_active', true)
            ->orderBy('item_name')
            ->get();

        $batches = ItemBatch::query()
            ->whereIn('item_uuid', $items->modelKeys())
            ->orderBy('expiry_date')
            ->get();

        // Two aggregate queries for the whole report, however many items and
        // batches there are (§5 trap 2). The Python version called
        // get_item_quantity and get_batch_quantity per row, which is why its
        // inventory report was the slowest screen in the app.
        $itemQuantities = $this->stock->quantitiesFor($items->modelKeys());
        $batchQuantities = $this->stock->batchQuantitiesFor($batches->modelKeys());

        $batchesByItem = $batches->groupBy('item_uuid');

        $report = [
            'generated_at' => BusinessDate::nowUtc(),
            'expiry_alert_days' => $alertDays,
            'items' => [],
            'total_lines' => 0,
            'total_value' => Money::ZERO,
            'low_stock_count' => 0,
            'expiring_count' => 0,
        ];

        foreach ($items as $item) {
            $qty = $itemQuantities[$item->uuid] ?? 0;
            $isLow = $qty <= $item->reorder_level;

            $batchRows = [];
            $itemExpiring = false;

            foreach ($batchesByItem->get($item->uuid, collect()) as $batch) {
                $batchQty = $batchQuantities[$batch->uuid] ?? 0;

                // Emptied batches are dropped, not listed at zero. A year of
                // trading leaves hundreds of them per item and none of them
                // says anything about what is on the shelf now.
                if ($batchQty <= 0) {
                    continue;
                }

                $isExpiring = $batch->expiry_date !== null
                    && $batch->expiry_date->lessThanOrEqualTo($cutoff);

                if ($isExpiring) {
                    $itemExpiring = true;
                }

                $batchRows[] = [
                    'batch_number' => $batch->batch_number,
                    'expiry_date' => $batch->expiry_date,
                    'qty' => $batchQty,
                    'purchase_price' => Money::format($batch->purchase_price),
                    'is_expiring' => $isExpiring,
                    'is_expired' => $batch->expiry_date !== null
                        && $batch->expiry_date->lessThan($today),
                ];
            }
            /*
             * Valued in PIECES. $qty is a piece count and purchase_price is the
             * cost of a whole pack, so multiplying the two directly would value
             * the shelf at pack_size times what it is worth - the kind of error
             * that is invisible until someone reconciles against an accountant.
             */
            $stockValue = Money::multiply($item->pieceCost(), $qty);

            $report['items'][] = [
                'uuid' => $item->uuid,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'manufacturer' => $item->manufacturer ?? '',
                'unit' => $item->unit_of_measure ?? '',
                'pack_size' => (int) $item->pack_size,
                'derived_qty' => $qty,
                'reorder_level' => $item->reorder_level,
                'is_low_stock' => $isLow,
                // Per piece, matching derived_qty. The pack figures are on the
                // item screen; a valuation report that mixed units would be read
                // wrong by whoever it is for.
                'purchase_price' => $item->pieceCost(),
                'sales_price' => $item->piecePrice(),
                'stock_value' => $stockValue,
                'batches' => $batchRows,
            ];

            $report['total_lines']++;
            $report['total_value'] = Money::add($report['total_value'], $stockValue);

            if ($isLow) {
                $report['low_stock_count']++;
            }

            if ($itemExpiring) {
                $report['expiring_count']++;
            }
        }

        return $report;
    }

    // -----------------------------------------------------------------------
    // Profit & loss
    // -----------------------------------------------------------------------

    /**
     * Revenue less cost of goods sold, per item, over a date range (spec §12.7).
     *
     * COGS is the purchase price of the batch the line actually came from, not
     * the item's current catalogue cost. Those diverge the moment a supplier
     * changes a price, and using the catalogue figure would restate the profit
     * on everything already sold every time a new delivery arrived.
     *
     * A line sold from no batch contributes zero cost, which flatters the
     * margin. That is visible rather than hidden: it only happens to stock that
     * entered outside the batch system, and the row shows a suspiciously
     * perfect profit.
     *
     * @return array<string, mixed>
     *
     * @throws ReportException when the range runs backwards
     */
    public function profitLoss(string $startDate, string $endDate): array
    {
        if ($endDate < $startDate) {
            throw new ReportException('The end of the range cannot fall before its start.');
        }

        $report = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rows' => [],
            'total_revenue' => Money::ZERO,
            'total_cogs' => Money::ZERO,
            'total_gross_profit' => Money::ZERO,
            'total_discount' => Money::ZERO,
            'total_returns' => Money::ZERO,
            'net_revenue' => Money::ZERO,
        ];

        /*
         * One query for every sold line in the range, with its item and the
         * batch it came from. The cost has to be worked out line by line - each
         * line's own batch price - so the arithmetic happens in PHP through
         * Money. Summing it in SQL would hand the total back through a float,
         * which is exactly the trap §5 names first.
         */
        $lines = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_uuid', '=', 'sales.uuid')
            ->join('items', 'sale_items.item_uuid', '=', 'items.uuid')
            ->leftJoin('item_batches', 'sale_items.batch_uuid', '=', 'item_batches.uuid')
            ->whereDate('sales.sale_date', '>=', $startDate)
            ->whereDate('sales.sale_date', '<=', $endDate)
            ->where('sales.is_deleted', false)
            ->select([
                'items.uuid as item_uuid',
                'items.item_code',
                'items.item_name',
                'sale_items.quantity',
                'sale_items.amount',
                'item_batches.purchase_price as batch_cost',
            ])
            ->get();

        $aggregated = [];

        foreach ($lines as $line) {
            $key = $line->item_uuid;

            $aggregated[$key] ??= [
                'item_code' => $line->item_code,
                'item_name' => $line->item_name,
                'qty_sold' => 0,
                'revenue' => Money::ZERO,
                'cogs' => Money::ZERO,
            ];

            $cogs = Money::multiply($line->batch_cost ?? 0, $line->quantity);

            $aggregated[$key]['qty_sold'] += (int) $line->quantity;
            $aggregated[$key]['revenue'] = Money::add($aggregated[$key]['revenue'], $line->amount);
            $aggregated[$key]['cogs'] = Money::add($aggregated[$key]['cogs'], $cogs);
        }

        usort($aggregated, fn ($a, $b) => strcasecmp($a['item_name'], $b['item_name']));

        foreach ($aggregated as $row) {
            $row['gross_profit'] = Money::sub($row['revenue'], $row['cogs']);
            $report['rows'][] = $row;
            $report['total_revenue'] = Money::add($report['total_revenue'], $row['revenue']);
            $report['total_cogs'] = Money::add($report['total_cogs'], $row['cogs']);
            $report['total_gross_profit'] = Money::add($report['total_gross_profit'], $row['gross_profit']);
        }

        $report['total_discount'] = Money::format(
            Sale::query()->whereDate('sale_date', '>=', $startDate)->whereDate('sale_date', '<=', $endDate)->sum('discount_amount')
        );

        $report['total_returns'] = Money::format(
            SaleReturn::query()->whereDate('return_date', '>=', $startDate)->whereDate('return_date', '<=', $endDate)->sum('total_amount')
        );

        /*
         * Net revenue subtracts discounts and returns from gross revenue but
         * NOT from gross profit. The cost of returned goods is not added back:
         * the stock came back to the shelf, so its cost belongs to whatever
         * sells it next, and crediting it here would count it twice.
         */
        $report['net_revenue'] = Money::sub(
            Money::sub($report['total_revenue'], $report['total_discount']),
            $report['total_returns'],
        );

        return $report;
    }

    // -----------------------------------------------------------------------
    // Shared internals
    // -----------------------------------------------------------------------

    /**
     * Quantity and value sold per medicine, for a set of sales, in one query.
     *
     * @param  list<string>  $saleUuids
     * @return array<string, array{qty: int, net: string}>
     */
    private function medicineBreakdown(array $saleUuids): array
    {
        if ($saleUuids === []) {
            return [];
        }

        $rows = DB::table('sale_items')
            ->join('items', 'sale_items.item_uuid', '=', 'items.uuid')
            ->whereIn('sale_items.sale_uuid', $saleUuids)
            ->select(['items.item_name', 'sale_items.quantity', 'sale_items.amount'])
            ->get();

        $breakdown = [];

        foreach ($rows as $row) {
            $breakdown[$row->item_name] ??= ['qty' => 0, 'net' => Money::ZERO];
            $breakdown[$row->item_name]['qty'] += (int) $row->quantity;
            $breakdown[$row->item_name]['net'] = Money::add($breakdown[$row->item_name]['net'], $row->amount);
        }

        // Biggest sellers first: the tail of a breakdown over a busy day is
        // dozens of single-box lines nobody reads.
        uasort($breakdown, fn ($a, $b) => Money::greaterThan($b['net'], $a['net']) ? 1 : -1);

        return $breakdown;
    }

    /**
     * Add one sale into a named bucket of a breakdown.
     *
     * @param  array<string, array{count: int, net: string}>  $buckets
     * @return array<string, array{count: int, net: string}>
     */
    private function accumulate(array $buckets, string $key, string $countField, string $net): array
    {
        $buckets[$key] ??= [$countField => 0, 'net' => Money::ZERO];
        $buckets[$key][$countField]++;
        $buckets[$key]['net'] = Money::add($buckets[$key]['net'], $net);

        return $buckets;
    }
}
