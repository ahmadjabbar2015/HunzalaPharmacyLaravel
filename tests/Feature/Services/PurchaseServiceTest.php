<?php

/*
 * Ported from tests/test_purchase_service.py.
 *
 * The distinction these tests protect: an ORDER is a plan and moves no stock; a
 * RECEIPT moves stock. An order that quietly added stock would have the shop
 * selling goods still on a supplier's van.
 */

use App\Exceptions\ItemException;
use App\Exceptions\PurchaseException;
use App\Models\ItemBatch;
use App\Models\PoItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DirectLine;
use App\Services\ItemService;
use App\Services\PoLine;
use App\Services\PurchaseService;
use App\Services\ReceiveLine;
use App\Services\StockService;
use App\Services\SupplierService;
use App\Support\BusinessDate;

beforeEach(function () {
    $this->purchases = app(PurchaseService::class);
    $this->stock = app(StockService::class);
    $this->items = app(ItemService::class);

    $this->user = User::factory()->create();

    $this->supplier = app(SupplierService::class)->create(
        supplierName: 'Abbott Distributors', deviceId: DEVICE,
    );

    $this->item = $this->items->create(itemCode: 'PANADOL-500', itemName: 'Panadol 500mg', deviceId: DEVICE);
});

/** Place a one-line order for $qty of the default item at $cost. */
function placeOrder(string $supplierUuid, string $itemUuid, int $qty = 100, string $cost = '12.00')
{
    return app(PurchaseService::class)->createOrder(
        supplierUuid: $supplierUuid,
        deviceId: DEVICE,
        lines: [new PoLine($itemUuid, $qty, $cost)],
    );
}

// ---------------------------------------------------------------------------
// orders move no stock
// ---------------------------------------------------------------------------

it('creates an order without moving any stock', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 100);

    expect($order->status)->toBe('ordered')
        ->and($order->supplier_uuid)->toBe($this->supplier->uuid)
        ->and($this->purchases->orderLines($order->uuid))->toHaveCount(1)
        // The goods are still on the supplier's van.
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(0)
        ->and(ItemBatch::count())->toBe(0);
});

it('numbers orders with the device prefix and PO', function () {
    $order = app(PurchaseService::class)->createOrder(
        supplierUuid: $this->supplier->uuid, deviceId: 'PC1',
        lines: [new PoLine($this->item->uuid, 10, '5.00')],
    );

    $day = BusinessDate::for()->format('Ymd');

    expect($order->po_number)->toBe("PC1-PO-{$day}-0001");
});

it('refuses an order with no lines', function () {
    expect(fn () => $this->purchases->createOrder(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE, lines: [],
    ))->toThrow(PurchaseException::class);
});

it('refuses a non-positive ordered quantity', function () {
    expect(fn () => placeOrder($this->supplier->uuid, $this->item->uuid, 0))
        ->toThrow(PurchaseException::class);

    expect(fn () => placeOrder($this->supplier->uuid, $this->item->uuid, -5))
        ->toThrow(PurchaseException::class);
});

it('refuses a negative unit cost but allows zero', function () {
    expect(fn () => placeOrder($this->supplier->uuid, $this->item->uuid, 10, '-1.00'))
        ->toThrow(PurchaseException::class);

    // Zero is a real thing a supplier sends: a free replacement or a sample.
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10, '0.00');

    expect($order->status)->toBe('ordered');
});

// ---------------------------------------------------------------------------
// cancelling
// ---------------------------------------------------------------------------

it('cancels an unreceived order', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid);

    expect($this->purchases->cancelOrder($order->uuid)->status)->toBe('cancelled');
});

it('refuses to cancel a fully received order', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10);
    $line = $this->purchases->orderLines($order->uuid)->first();

    $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-1', '2027-01-01', 10)],
    );

    // The goods are on the shelves and the shop owes for them. Cancelling would
    // deny a delivery it has already taken.
    expect(fn () => $this->purchases->cancelOrder($order->uuid))
        ->toThrow(PurchaseException::class);
});

it('refuses to receive against a cancelled order', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10);
    $line = $this->purchases->orderLines($order->uuid)->first();
    $this->purchases->cancelOrder($order->uuid);

    expect(fn () => $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-1', '2027-01-01', 5)],
    ))->toThrow(PurchaseException::class);
});

// ---------------------------------------------------------------------------
// receiving against an order
// ---------------------------------------------------------------------------

it('creates the batch and the ledger row on receipt', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 100, '12.00');
    $line = $this->purchases->orderLines($order->uuid)->first();

    $receipt = $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-2211', '2027-06-01', 100)],
        invoiceReference: 'ABB-9912',
    );

    $batch = ItemBatch::where('batch_number', 'B-2211')->first();

    expect($receipt->total_amount)->toBe('1200.00')          // 100 x 12.00
        ->and($receipt->invoice_reference)->toBe('ABB-9912')
        ->and($receipt->po_uuid)->toBe($order->uuid)
        ->and($batch)->not->toBeNull()
        ->and($batch->expiry_date->toDateString())->toBe('2027-06-01')
        // The stock is real now.
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(100)
        ->and($this->stock->batchQuantityFor($batch->uuid))->toBe(100);
});

it('stamps the supplier on the batch it received', function () {
    // Needed to trace a recall back to who supplied the lot.
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10);
    $line = $this->purchases->orderLines($order->uuid)->first();

    $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-1', '2027-01-01', 10)],
    );

    expect(ItemBatch::first()->supplier_uuid)->toBe($this->supplier->uuid);
});

it('marks an order fully received', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10);
    $line = $this->purchases->orderLines($order->uuid)->first();

    $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-1', '2027-01-01', 10)],
    );

    expect($order->fresh()->status)->toBe('received')
        ->and(PoItem::find($line->uuid)->quantity_received)->toBe(10);
});

it('marks an order partially received and accumulates across receipts', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 100);
    $line = $this->purchases->orderLines($order->uuid)->first();

    // Suppliers split deliveries routinely, so this is the normal path.
    $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-FIRST', '2027-01-01', 60)],
    );

    expect($order->fresh()->status)->toBe('partially_received')
        ->and(PoItem::find($line->uuid)->quantity_received)->toBe(60)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(60);

    $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-SECOND', '2027-03-01', 40)],
    );

    expect($order->fresh()->status)->toBe('received')
        ->and(PoItem::find($line->uuid)->quantity_received)->toBe(100)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(100)
        // Two deliveries, two batches, each with its own expiry.
        ->and(ItemBatch::count())->toBe(2);
});

it('refuses to over-receive a line', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10);
    $line = $this->purchases->orderLines($order->uuid)->first();

    expect(fn () => $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-1', '2027-01-01', 11)],
    ))->toThrow(PurchaseException::class);
});

it('counts earlier receipts against the remaining quantity', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10);
    $line = $this->purchases->orderLines($order->uuid)->first();

    $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-1', '2027-01-01', 7)],
    );

    // 3 remain, so 4 must be refused.
    expect(fn () => $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-2', '2027-01-01', 4)],
    ))->toThrow(PurchaseException::class);
});

it('caps a PO line named twice within one receipt', function () {
    // 6 and 6 each pass against 10 in isolation; accumulated they do not.
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10);
    $line = $this->purchases->orderLines($order->uuid)->first();

    expect(fn () => $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [
            new ReceiveLine($line->uuid, 'B-1', '2027-01-01', 6),
            new ReceiveLine($line->uuid, 'B-2', '2027-01-01', 6),
        ],
    ))->toThrow(PurchaseException::class);
});

it('refuses a line that is not on the order', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10);
    $otherOrder = placeOrder($this->supplier->uuid, $this->item->uuid, 10);
    $otherLine = $this->purchases->orderLines($otherOrder->uuid)->first();

    expect(fn () => $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($otherLine->uuid, 'B-1', '2027-01-01', 5)],
    ))->toThrow(PurchaseException::class);
});

it('refuses a receipt with no lines', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10);

    expect(fn () => $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE, lines: [],
    ))->toThrow(PurchaseException::class);
});

it('takes the delivered cost over the ordered cost when given one', function () {
    // Suppliers change a price between order and delivery, and the shop owes
    // what was invoiced rather than what was quoted.
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10, '12.00');
    $line = $this->purchases->orderLines($order->uuid)->first();

    $receipt = $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-1', '2027-01-01', 10, unitCost: '13.50')],
    );

    expect($receipt->total_amount)->toBe('135.00')
        ->and(PurchaseItem::first()->unit_cost)->toBe('13.50')
        // The batch carries what was actually paid, so valuation stays right.
        ->and(ItemBatch::first()->purchase_price)->toBe('13.50');
});

it('falls back to the ordered cost when the receipt names none', function () {
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10, '12.00');
    $line = $this->purchases->orderLines($order->uuid)->first();

    $receipt = $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-1', '2027-01-01', 10)],
    );

    expect($receipt->total_amount)->toBe('120.00');
});

it('totals a multi-line receipt exactly', function () {
    $second = $this->items->create(itemCode: 'BRUFEN', itemName: 'Brufen', deviceId: DEVICE);

    $order = $this->purchases->createOrder(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [
            new PoLine($this->item->uuid, 3, '19.99'),
            new PoLine($second->uuid, 7, '0.33'),
        ],
    );

    $lines = $this->purchases->orderLines($order->uuid);

    $receipt = $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [
            new ReceiveLine($lines[0]->uuid, 'B-A', '2027-01-01', 3),
            new ReceiveLine($lines[1]->uuid, 'B-B', '2027-01-01', 7),
        ],
    );

    // 3 x 19.99 + 7 x 0.33 = 59.97 + 2.31 = 62.28
    expect($receipt->total_amount)->toBe('62.28');
});

it('leaves nothing behind when a receipt line fails', function () {
    // The receipt, its batches and its ledger rows are one transaction. A
    // half-received delivery would leave stock on the shelves with no invoice
    // to pay against it.
    $order = placeOrder($this->supplier->uuid, $this->item->uuid, 10);
    $line = $this->purchases->orderLines($order->uuid)->first();

    // Receive a batch, then try to receive the SAME batch number again - which
    // ItemService rejects, mid-transaction.
    $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-DUP', '2027-01-01', 5)],
    );

    $purchasesBefore = Purchase::count();

    expect(fn () => $this->purchases->receiveAgainstOrder(
        orderUuid: $order->uuid, deviceId: DEVICE,
        lines: [new ReceiveLine($line->uuid, 'B-DUP', '2027-01-01', 5)],
    ))->toThrow(ItemException::class);

    expect(Purchase::count())->toBe($purchasesBefore)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(5)
        ->and(PoItem::find($line->uuid)->quantity_received)->toBe(5);
});

// ---------------------------------------------------------------------------
// direct receipts - a walk-in delivery with no order
// ---------------------------------------------------------------------------

it('receives a direct delivery with no order', function () {
    $receipt = $this->purchases->receiveDirect(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new DirectLine($this->item->uuid, 'B-WALKIN', '2027-08-01', 25, '10.00')],
        invoiceReference: 'INV-771',
    );

    expect($receipt->po_uuid)->toBeNull()
        ->and($receipt->total_amount)->toBe('250.00')
        ->and($receipt->invoice_reference)->toBe('INV-771')
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(25)
        ->and(ItemBatch::where('batch_number', 'B-WALKIN')->exists())->toBeTrue();
});

it('numbers receipts with the device prefix and GRN', function () {
    $receipt = $this->purchases->receiveDirect(
        supplierUuid: $this->supplier->uuid, deviceId: 'PC1',
        lines: [new DirectLine($this->item->uuid, 'B-1', '2027-01-01', 1, '1.00')],
    );

    $day = BusinessDate::for()->format('Ymd');

    // GRN keeps a receipt number from being mistaken for an order or an invoice.
    expect($receipt->purchase_number)->toBe("PC1-GRN-{$day}-0001");
});

it('refuses a direct receipt with no lines or a bad quantity', function () {
    expect(fn () => $this->purchases->receiveDirect(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE, lines: [],
    ))->toThrow(PurchaseException::class);

    expect(fn () => $this->purchases->receiveDirect(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new DirectLine($this->item->uuid, 'B-1', '2027-01-01', 0, '1.00')],
    ))->toThrow(PurchaseException::class);
});

it('requires an expiry date on received goods', function () {
    // Enforced by ItemService, which every receipt goes through - so a goods
    // receipt cannot bypass the checks a manual batch entry is held to.
    expect(fn () => $this->purchases->receiveDirect(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new DirectLine($this->item->uuid, 'B-1', '', 5, '1.00')],
    ))->toThrow(ItemException::class);
});

// ---------------------------------------------------------------------------
// listing
// ---------------------------------------------------------------------------

it('lists orders and receipts by supplier', function () {
    $other = app(SupplierService::class)->create(supplierName: 'Getz Pharma', deviceId: DEVICE);

    placeOrder($this->supplier->uuid, $this->item->uuid);
    placeOrder($other->uuid, $this->item->uuid);

    $this->purchases->receiveDirect(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new DirectLine($this->item->uuid, 'B-1', '2027-01-01', 5, '1.00')],
    );

    expect($this->purchases->listOrders($this->supplier->uuid))->toHaveCount(1)
        ->and($this->purchases->listOrders())->toHaveCount(2)
        ->and($this->purchases->listPurchases($this->supplier->uuid))->toHaveCount(1)
        ->and($this->purchases->listPurchases($other->uuid))->toHaveCount(0);
});

it('filters orders by status', function () {
    $open = placeOrder($this->supplier->uuid, $this->item->uuid);
    $cancelled = placeOrder($this->supplier->uuid, $this->item->uuid);
    $this->purchases->cancelOrder($cancelled->uuid);

    expect($this->purchases->listOrders(status: 'ordered')->pluck('uuid')->all())->toBe([$open->uuid]);
});
