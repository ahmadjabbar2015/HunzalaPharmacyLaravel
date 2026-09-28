<?php

namespace App\Http\Controllers;

use App\Exceptions\ReturnException;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Services\ReturnLine;
use App\Services\ReturnService;
use App\Services\SessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/*
 * Processing a return.
 *
 * Three screens: find the original sale, choose what comes back, and the record
 * of what was refunded. The flow starts from the SALE rather than from the item,
 * because a refund has to be at the price the customer actually paid - and only
 * the sale knows that.
 *
 * Staff-level, deliberately. A customer at the counter with a faulty box should
 * not wait for a manager; the refund is capped at what was sold and every return
 * is attributed, which is the real control.
 */
class ReturnController extends Controller
{
    public function __construct(
        private readonly ReturnService $returns,
        private readonly SessionService $sessions,
    ) {}

    /** The list of returns already processed. */
    public function index(): View
    {
        return view('returns.index', [
            'returns' => SaleReturn::query()
                ->with(['originalSale', 'staffMember', 'customer'])
                ->latest('return_time')
                ->paginate(50),
        ]);
    }

    /**
     * Find the sale being returned against.
     *
     * By invoice number when the receipt is to hand, by phone number when it is
     * not - which is most of the time.
     */
    public function find(Request $request): View
    {
        $filters = $request->validate([
            'invoice' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $sale = null;
        $sales = collect();
        $searched = false;

        if (! empty($filters['invoice'])) {
            $searched = true;
            $sale = $this->returns->findSaleByInvoice($filters['invoice']);
        } elseif (! empty($filters['phone'])) {
            $searched = true;
            $sales = $this->returns->findSalesByPhone($filters['phone']);
        }

        return view('returns.find', [
            'filters' => $filters,
            'sale' => $sale,
            'sales' => $sales,
            'searched' => $searched,
        ]);
    }

    /**
     * Choose what comes back.
     *
     * The form shows what REMAINS returnable per line, not the original
     * quantity. Showing the original is how the same line gets refunded twice.
     */
    public function create(Sale $sale): View
    {
        return view('returns.create', [
            'sale' => $sale,
            'rows' => $this->returns->returnableItems($sale->uuid),
            'drawer' => $this->sessions->openSession(),
        ]);
    }

    public function store(Request $request, Sale $sale): RedirectResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_item_uuid' => ['required', 'string'],
            // Nullable so an untouched line can simply be left at zero rather
            // than forcing staff to delete rows from the form.
            'lines.*.quantity' => ['nullable', 'integer', 'min:0'],
            'refund_method' => ['required', Rule::in(config('pharmacy.payment_methods'))],
            'payment_reference' => ['nullable', 'string', 'max:80'],
        ]);

        $lines = [];

        foreach ($validated['lines'] as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity > 0) {
                $lines[] = new ReturnLine($line['sale_item_uuid'], $quantity);
            }
        }

        if ($lines === []) {
            return back()->withInput()->withErrors([
                'lines' => 'Enter a quantity against at least one line.',
            ]);
        }

        try {
            $return = $this->returns->create(
                originalSaleUuid: $sale->uuid,
                lines: $lines,
                staffMemberUuid: $request->user()->uuid,
                deviceId: config('pharmacy.device_id'),
                // Attached to the open drawer, so a cash refund comes off tonight's
                // expected cash. Without this the drawer would read over by the
                // refund amount and nobody could say why.
                sessionUuid: $this->sessions->openSession()?->uuid,
                refundMethod: $validated['refund_method'],
                paymentReference: $validated['payment_reference'] ?? null,
            );
        } catch (ReturnException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('returns.show', $return)
            ->with('status', "Refund of {$return->total_amount} recorded. The stock is back on the shelf.");
    }

    public function show(SaleReturn $return): View
    {
        return view('returns.show', [
            'return' => $return->load(['lines.item', 'originalSale', 'staffMember', 'customer']),
        ]);
    }

    /**
     * The refund slip.
     *
     * A separate print layout with no navigation, sized for the thermal roll.
     */
    public function receipt(SaleReturn $return): View
    {
        return view('returns.receipt', [
            'return' => $return->load(['lines.item', 'originalSale', 'staffMember']),
            'settings' => Setting::query()->first(),
        ]);
    }
}
