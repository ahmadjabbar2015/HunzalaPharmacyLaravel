<?php

namespace App\Http\Controllers;

use App\Exceptions\SupplierException;
use App\Models\Supplier;
use App\Services\PurchaseService;
use App\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/*
 * Suppliers and what the shop owes them.
 *
 * Every balance shown here is derived - opening balance plus purchases less
 * payments, recomputed from the rows each time. The same principle the stock
 * ledger rests on, for the same reason: a stored balance drifts, and a drifted
 * supplier balance is an argument with someone the shop has to keep buying from.
 */
class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierService $suppliers,
        private readonly PurchaseService $purchases,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'show' => ['nullable', 'in:active,all'],
        ]);

        $suppliers = $this->suppliers->list(
            search: $filters['q'] ?? null,
            activeOnly: ($filters['show'] ?? 'active') === 'active',
        );

        return view('suppliers.index', [
            'suppliers' => $suppliers,
            // Three queries for the whole list rather than three per row: the
            // list shows a balance against every supplier.
            'balances' => $this->suppliers->outstandingBalances($suppliers->pluck('uuid')->all()),
            'filters' => $filters,
        ]);
    }

    public function show(Supplier $supplier): View
    {
        return view('suppliers.show', [
            'supplier' => $supplier,
            'balance' => $this->suppliers->outstandingBalance($supplier->uuid),
            'payments' => $this->suppliers->payments($supplier->uuid),
            'purchases' => $this->purchases->listPurchases($supplier->uuid),
            'orders' => $this->purchases->listOrders($supplier->uuid),
        ]);
    }

    public function create(): View
    {
        return view('suppliers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:2000'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $supplier = $this->suppliers->create(
                supplierName: $validated['supplier_name'],
                deviceId: config('pharmacy.device_id'),
                contactPerson: $validated['contact_person'] ?? null,
                phone: $validated['phone'] ?? null,
                address: $validated['address'] ?? null,
                openingBalance: $validated['opening_balance'] ?? 0,
                paymentTerms: $validated['payment_terms'] ?? null,
            );
        } catch (SupplierException $e) {
            return back()->withInput()->withErrors(['supplier_name' => $e->getMessage()]);
        }

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Supplier added.');
    }

    public function edit(Supplier $supplier): View
    {
        return view('suppliers.edit', ['supplier' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
        ]);

        // opening_balance is deliberately absent. It is the agreed starting
        // position, and editing it would silently rewrite every balance computed
        // since, with nothing to say it had changed.
        $validated['is_active'] = $request->boolean('is_active');

        try {
            $this->suppliers->update($supplier->uuid, $validated);
        } catch (SupplierException $e) {
            return back()->withInput()->withErrors(['supplier_name' => $e->getMessage()]);
        }

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Supplier updated.');
    }

    /**
     * Record money paid to a supplier.
     *
     * A negative amount is accepted on purpose: it is how a mistaken payment is
     * corrected, by a compensating entry rather than by editing the original.
     * The same rule the stock ledger follows.
     */
    public function storePayment(Request $request, Supplier $supplier): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0'],
            'payment_method' => ['required', Rule::in(config('pharmacy.supplier_payment_methods'))],
            'payment_date' => ['nullable', 'date', 'before:tomorrow'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->suppliers->recordPayment(
                supplierUuid: $supplier->uuid,
                amount: $validated['amount'],
                deviceId: config('pharmacy.device_id'),
                paymentMethod: $validated['payment_method'],
                paymentDate: $validated['payment_date'] ?? null,
                reference: $validated['reference'] ?? null,
                notes: $validated['notes'] ?? null,
                performedByUserUuid: $request->user()->uuid,
            );
        } catch (SupplierException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Payment recorded.');
    }
}
