<?php

namespace App\Http\Controllers;

use App\Exceptions\ItemException;
use App\Exceptions\PurchaseException;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Services\DirectLine;
use App\Services\PoLine;
use App\Services\PurchaseService;
use App\Services\ReceiveLine;
use App\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/*
 * Purchase orders and goods receipts.
 *
 * An ORDER is a plan and moves no stock. A RECEIPT moves stock, always through
 * ItemService and StockService, so the batch and its ledger row are created
 * together. An order that quietly added stock would have the shop selling goods
 * still on a supplier's van.
 */
class PurchasingController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchases,
        private readonly SupplierService $suppliers,
    ) {}

    // -----------------------------------------------------------------------
    // purchase orders
    // -----------------------------------------------------------------------

    public function orders(Request $request): View
    {
        $filters = $request->validate([
            'supplier' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,ordered,partially_received,received,cancelled'],
        ]);

        return view('purchasing.orders.index', [
            'orders' => $this->purchases->listOrders(
                supplierUuid: $filters['supplier'] ?? null,
                status: $filters['status'] ?? null,
            )->load('supplier'),
            'suppliers' => $this->suppliers->list(),
            'filters' => $filters,
        ]);
    }

    public function createOrder(): View
    {
        return view('purchasing.orders.create', [
            'suppliers' => $this->suppliers->list(),
            // Low-stock items first: an order form that opens on what needs
            // reordering is one fewer screen to visit.
            'items' => Item::query()->where('is_active', true)->orderBy('item_name')->get(),
        ]);
    }

    public function storeOrder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_uuid' => ['required', 'string', 'exists:suppliers,uuid'],
            'order_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_uuid' => ['nullable', 'string'],
            'lines.*.quantity_ordered' => ['nullable', 'integer', 'min:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = [];

        foreach ($validated['lines'] as $line) {
            // Blank rows are skipped rather than rejected: the form offers more
            // rows than most orders use.
            if (empty($line['item_uuid']) || (int) ($line['quantity_ordered'] ?? 0) <= 0) {
                continue;
            }

            $lines[] = new PoLine(
                itemUuid: $line['item_uuid'],
                quantityOrdered: (int) $line['quantity_ordered'],
                unitCost: (string) ($line['unit_cost'] ?? '0'),
            );
        }

        if ($lines === []) {
            return back()->withInput()->withErrors([
                'lines' => 'Add at least one item with a quantity.',
            ]);
        }

        try {
            $order = $this->purchases->createOrder(
                supplierUuid: $validated['supplier_uuid'],
                deviceId: config('pharmacy.device_id'),
                lines: $lines,
                orderDate: $validated['order_date'] ?? null,
                notes: $validated['notes'] ?? null,
            );
        } catch (PurchaseException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('purchasing.orders.show', $order)
            ->with('status', "Order {$order->po_number} placed. No stock moves until it is received.");
    }

    public function showOrder(PurchaseOrder $order): View
    {
        return view('purchasing.orders.show', [
            'order' => $order->load('supplier'),
            'lines' => $this->purchases->orderLines($order->uuid)->load('item'),
            'receipts' => Purchase::query()->where('po_uuid', $order->uuid)->latest('purchase_date')->get(),
        ]);
    }

    public function cancelOrder(PurchaseOrder $order): RedirectResponse
    {
        try {
            $this->purchases->cancelOrder($order->uuid);
        } catch (PurchaseException $e) {
            // A fully received order lands here: the goods are on the shelves and
            // the shop owes for them.
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('purchasing.orders.show', $order)->with('status', 'Order cancelled.');
    }

    // -----------------------------------------------------------------------
    // receiving against an order
    // -----------------------------------------------------------------------

    public function receiveForm(PurchaseOrder $order): View
    {
        return view('purchasing.orders.receive', [
            'order' => $order->load('supplier'),
            'lines' => $this->purchases->orderLines($order->uuid)->load('item'),
        ]);
    }

    public function receive(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_reference' => ['nullable', 'string', 'max:80'],
            'purchase_date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.po_item_uuid' => ['required', 'string'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:0'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:80'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.mfg_date' => ['nullable', 'date'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = [];

        foreach ($validated['lines'] as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            // Checked here rather than as a blanket rule, because these are only
            // required on a line actually being received - most rows on a partial
            // delivery are left empty.
            if (empty($line['batch_number']) || empty($line['expiry_date'])) {
                return back()->withInput()->withErrors([
                    'lines' => 'A line being received needs both a batch number and an expiry date.',
                ]);
            }

            $lines[] = new ReceiveLine(
                poItemUuid: $line['po_item_uuid'],
                batchNumber: $line['batch_number'],
                expiryDate: $line['expiry_date'],
                quantity: $quantity,
                unitCost: isset($line['unit_cost']) ? (string) $line['unit_cost'] : null,
                mfgDate: $line['mfg_date'] ?? null,
            );
        }

        if ($lines === []) {
            return back()->withInput()->withErrors([
                'lines' => 'Enter a quantity against at least one line.',
            ]);
        }

        try {
            $receipt = $this->purchases->receiveAgainstOrder(
                orderUuid: $order->uuid,
                deviceId: config('pharmacy.device_id'),
                lines: $lines,
                invoiceReference: $validated['invoice_reference'] ?? null,
                purchaseDate: $validated['purchase_date'] ?? null,
                performedByUserUuid: $request->user()->uuid,
            );
        } catch (PurchaseException|ItemException $e) {
            // ItemException too: a duplicate batch number is caught by
            // ItemService mid-receipt, and the whole receipt rolls back.
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('purchasing.receipts.show', $receipt)
            ->with('status', "Received {$receipt->purchase_number}. The stock is on the shelf.");
    }

    // -----------------------------------------------------------------------
    // goods receipts
    // -----------------------------------------------------------------------

    public function receipts(Request $request): View
    {
        $filters = $request->validate(['supplier' => ['nullable', 'string']]);

        return view('purchasing.receipts.index', [
            'receipts' => $this->purchases->listPurchases($filters['supplier'] ?? null)
                ->load(['supplier', 'purchaseOrder']),
            'suppliers' => $this->suppliers->list(),
            'filters' => $filters,
        ]);
    }

    public function showReceipt(Purchase $receipt): View
    {
        return view('purchasing.receipts.show', [
            'receipt' => $receipt->load(['supplier', 'purchaseOrder']),
            'lines' => $this->purchases->purchaseLines($receipt->uuid)->load(['item', 'batch']),
        ]);
    }

    /** A walk-in delivery with no prior order. */
    public function createDirect(): View
    {
        return view('purchasing.receipts.create', [
            'suppliers' => $this->suppliers->list(),
            'items' => Item::query()->where('is_active', true)->orderBy('item_name')->get(),
        ]);
    }

    public function storeDirect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_uuid' => ['required', 'string', 'exists:suppliers,uuid'],
            'invoice_reference' => ['nullable', 'string', 'max:80'],
            'purchase_date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_uuid' => ['nullable', 'string'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:0'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:80'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.mfg_date' => ['nullable', 'date'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = [];

        foreach ($validated['lines'] as $line) {
            if (empty($line['item_uuid']) || (int) ($line['quantity'] ?? 0) <= 0) {
                continue;
            }

            if (empty($line['batch_number']) || empty($line['expiry_date'])) {
                return back()->withInput()->withErrors([
                    'lines' => 'Every line needs a batch number and an expiry date.',
                ]);
            }

            $lines[] = new DirectLine(
                itemUuid: $line['item_uuid'],
                batchNumber: $line['batch_number'],
                expiryDate: $line['expiry_date'],
                quantity: (int) $line['quantity'],
                unitCost: (string) ($line['unit_cost'] ?? '0'),
                mfgDate: $line['mfg_date'] ?? null,
            );
        }

        if ($lines === []) {
            return back()->withInput()->withErrors(['lines' => 'Add at least one item with a quantity.']);
        }

        try {
            $receipt = $this->purchases->receiveDirect(
                supplierUuid: $validated['supplier_uuid'],
                deviceId: config('pharmacy.device_id'),
                lines: $lines,
                invoiceReference: $validated['invoice_reference'] ?? null,
                purchaseDate: $validated['purchase_date'] ?? null,
                performedByUserUuid: $request->user()->uuid,
            );
        } catch (PurchaseException|ItemException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('purchasing.receipts.show', $receipt)
            ->with('status', "Received {$receipt->purchase_number}. The stock is on the shelf.");
    }
}
