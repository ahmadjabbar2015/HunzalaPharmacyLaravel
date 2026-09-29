<?php

/*
 * The supplier and purchasing screens.
 *
 * The rules are proved in SupplierServiceTest and PurchaseServiceTest. These check
 * the HTTP layer: that an order moves no stock, that a receipt does, that the
 * over-receive cap holds against a hand-crafted POST, and that a rule violation
 * comes back as a message rather than a 500.
 */

use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\PoItem;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\DirectLine;
use App\Services\PoLine;
use App\Services\PurchaseService;
use App\Services\StockService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->staff = User::factory()->create(['username' => 'ahmed']);
    $this->stock = app(StockService::class);
    $this->suppliers = app(SupplierService::class);

    $this->supplier = $this->suppliers->create(
        supplierName: 'Abbott Distributors',
        deviceId: DEVICE,
        contactPerson: 'Mr Khan',
        openingBalance: '5000.00',
    );

    $this->item = Item::factory()->create(['item_code' => 'PANADOL-500', 'item_name' => 'Panadol 500mg']);
});

/** Place a one-line order through the HTTP layer. */
function postOrder(array $overrides = []): array
{
    return array_replace_recursive([
        'supplier_uuid' => null,
        'lines' => [['item_uuid' => null, 'quantity_ordered' => 100, 'unit_cost' => '12.00']],
    ], $overrides);
}

// ---------------------------------------------------------------------------
// suppliers
// ---------------------------------------------------------------------------

it('lists suppliers with a derived balance on every row', function () {
    $this->actingAs($this->staff)->get(route('suppliers.index'))
        ->assertOk()
        ->assertSee('Abbott Distributors')
        ->assertSee('5000.00');
});

it('computes the whole list of balances in a handful of queries', function () {
    // The list shows a balance per row, which is the same N+1 trap as derived
    // stock: three queries per supplier would not scale.
    foreach (range(1, 15) as $n) {
        $this->suppliers->create(supplierName: "Supplier {$n}", deviceId: DEVICE);
    }

    $this->actingAs($this->staff);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get(route('suppliers.index'))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThan(20);

    DB::disableQueryLog();
});

it('adds a supplier', function () {
    $this->actingAs($this->staff)->post(route('suppliers.store'), [
        'supplier_name' => 'Getz Pharma',
        'contact_person' => 'Ms Iqbal',
        'opening_balance' => '1500.00',
    ])->assertRedirect();

    $created = Supplier::where('supplier_name', 'Getz Pharma')->first();

    expect($created)->not->toBeNull()
        ->and($created->opening_balance)->toBe('1500.00');
});

it('refuses a blank supplier name', function () {
    $this->actingAs($this->staff)->post(route('suppliers.store'), ['supplier_name' => '   '])
        ->assertSessionHasErrors('supplier_name');
});

it('refuses to change an opening balance through the edit form', function () {
    // It is the agreed starting position. A hand-crafted POST must not rewrite
    // every balance computed since.
    $this->actingAs($this->staff)->put(route('suppliers.update', $this->supplier), [
        'supplier_name' => 'Abbott Distributors',
        'opening_balance' => '0.00',
    ])->assertRedirect();

    expect($this->supplier->fresh()->opening_balance)->toBe('5000.00');
});

it('shows a supplier account with its balance and history', function () {
    $this->actingAs($this->staff)->get(route('suppliers.show', $this->supplier))
        ->assertOk()
        ->assertSee('Abbott Distributors')
        ->assertSee('5000.00');
});

// ---------------------------------------------------------------------------
// supplier payments
// ---------------------------------------------------------------------------

it('records a payment and reduces the balance', function () {
    $this->actingAs($this->staff)->post(route('suppliers.payments.store', $this->supplier), [
        'amount' => '2000.00',
        'payment_method' => 'bank_transfer',
    ])->assertRedirect();

    expect(SupplierPayment::count())->toBe(1)
        ->and($this->suppliers->outstandingBalance($this->supplier->uuid))->toBe('3000.00');
});

it('accepts a negative payment as a correction', function () {
    // A mistaken payment is corrected by a compensating entry, never by editing
    // the original - the same rule the stock ledger follows.
    $this->actingAs($this->staff)->post(route('suppliers.payments.store', $this->supplier), [
        'amount' => '2000.00', 'payment_method' => 'cash',
    ]);

    $this->actingAs($this->staff)->post(route('suppliers.payments.store', $this->supplier), [
        'amount' => '-500.00', 'payment_method' => 'cash', 'notes' => 'corrects an overpayment',
    ])->assertRedirect();

    expect(SupplierPayment::count())->toBe(2)
        ->and($this->suppliers->outstandingBalance($this->supplier->uuid))->toBe('3500.00');
});

it('refuses a zero payment', function () {
    $this->actingAs($this->staff)->post(route('suppliers.payments.store', $this->supplier), [
        'amount' => '0', 'payment_method' => 'cash',
    ])->assertSessionHasErrors('amount');
});

it('refuses a payment method the shop does not use', function () {
    $this->actingAs($this->staff)->post(route('suppliers.payments.store', $this->supplier), [
        'amount' => '100', 'payment_method' => 'barter',
    ])->assertSessionHasErrors('payment_method');
});

it('accepts the wider supplier payment methods', function () {
    // Suppliers are paid by bank transfer and cheque, unlike POS customers.
    foreach (['cash', 'mobile', 'bank_transfer', 'cheque'] as $method) {
        $this->actingAs($this->staff)->post(route('suppliers.payments.store', $this->supplier), [
            'amount' => '10.00', 'payment_method' => $method,
        ])->assertRedirect();
    }

    expect(SupplierPayment::count())->toBe(4);
});

it('refuses a payment dated in the future', function () {
    $this->actingAs($this->staff)->post(route('suppliers.payments.store', $this->supplier), [
        'amount' => '100', 'payment_method' => 'cash',
        'payment_date' => now()->addWeek()->toDateString(),
    ])->assertSessionHasErrors('payment_date');
});

// ---------------------------------------------------------------------------
// purchase orders move no stock
// ---------------------------------------------------------------------------

it('places an order without moving any stock', function () {
    $this->actingAs($this->staff)->post(route('purchasing.orders.store'), postOrder([
        'supplier_uuid' => $this->supplier->uuid,
        'lines' => [['item_uuid' => $this->item->uuid]],
    ]))->assertRedirect();

    $order = PurchaseOrder::first();

    expect($order)->not->toBeNull()
        ->and($order->status)->toBe('ordered')
        // The goods are still on the supplier's van.
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(0)
        ->and(ItemBatch::count())->toBe(0);
});

it('skips blank rows on the order form', function () {
    // The form offers eight rows and most orders use two.
    $this->actingAs($this->staff)->post(route('purchasing.orders.store'), [
        'supplier_uuid' => $this->supplier->uuid,
        'lines' => [
            ['item_uuid' => $this->item->uuid, 'quantity_ordered' => 10, 'unit_cost' => '12.00'],
            ['item_uuid' => '', 'quantity_ordered' => '', 'unit_cost' => ''],
            ['item_uuid' => $this->item->uuid, 'quantity_ordered' => 0, 'unit_cost' => '12.00'],
        ],
    ])->assertRedirect();

    expect(PoItem::count())->toBe(1);
});

it('refuses an order with no usable lines', function () {
    $this->actingAs($this->staff)->post(route('purchasing.orders.store'), [
        'supplier_uuid' => $this->supplier->uuid,
        'lines' => [['item_uuid' => '', 'quantity_ordered' => 0]],
    ])->assertSessionHasErrors('lines');

    expect(PurchaseOrder::count())->toBe(0);
});

it('refuses an order against an unknown supplier', function () {
    $this->actingAs($this->staff)->post(route('purchasing.orders.store'), [
        'supplier_uuid' => 'no-such-supplier',
        'lines' => [['item_uuid' => $this->item->uuid, 'quantity_ordered' => 1, 'unit_cost' => '1']],
    ])->assertSessionHasErrors('supplier_uuid');
});

it('cancels an unreceived order', function () {
    $order = app(PurchaseService::class)->createOrder(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new PoLine($this->item->uuid, 10, '12.00')],
    );

    $this->actingAs($this->staff)->post(route('purchasing.orders.cancel', $order))->assertRedirect();

    expect($order->fresh()->status)->toBe('cancelled');
});

// ---------------------------------------------------------------------------
// receiving against an order
// ---------------------------------------------------------------------------

it('receives a delivery and puts the stock on the shelf', function () {
    $order = app(PurchaseService::class)->createOrder(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new PoLine($this->item->uuid, 100, '12.00')],
    );
    $line = PoItem::first();

    $this->actingAs($this->staff)->post(route('purchasing.orders.receive', $order), [
        'invoice_reference' => 'ABB-9912',
        'lines' => [[
            'po_item_uuid' => $line->uuid,
            'quantity' => 100,
            'batch_number' => 'B-2211',
            'expiry_date' => '2027-06-01',
            'unit_cost' => '12.00',
        ]],
    ])->assertRedirect();

    $receipt = Purchase::first();

    expect($receipt->total_amount)->toBe('1200.00')
        ->and($receipt->invoice_reference)->toBe('ABB-9912')
        // The stock is real now, with its expiry tracked.
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(100)
        ->and(ItemBatch::where('batch_number', 'B-2211')->first()->supplier_uuid)->toBe($this->supplier->uuid)
        ->and($order->fresh()->status)->toBe('received')
        // And the value is added to what the shop owes.
        ->and($this->suppliers->outstandingBalance($this->supplier->uuid))->toBe('6200.00');
});

it('records a split delivery as partially received', function () {
    // Suppliers split deliveries routinely, so this is the normal path.
    $order = app(PurchaseService::class)->createOrder(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new PoLine($this->item->uuid, 100, '12.00')],
    );
    $line = PoItem::first();

    $this->actingAs($this->staff)->post(route('purchasing.orders.receive', $order), [
        'lines' => [[
            'po_item_uuid' => $line->uuid, 'quantity' => 60,
            'batch_number' => 'B-FIRST', 'expiry_date' => '2027-06-01',
        ]],
    ])->assertRedirect();

    expect($order->fresh()->status)->toBe('partially_received')
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(60);

    $this->actingAs($this->staff)->post(route('purchasing.orders.receive', $order), [
        'lines' => [[
            'po_item_uuid' => $line->uuid, 'quantity' => 40,
            'batch_number' => 'B-SECOND', 'expiry_date' => '2027-09-01',
        ]],
    ])->assertRedirect();

    expect($order->fresh()->status)->toBe('received')
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(100)
        // Two deliveries, two batches, each with its own expiry.
        ->and(ItemBatch::count())->toBe(2);
});

it('holds the over-receive cap against a hand-crafted post', function () {
    // The form caps the input with max=, which a crafted POST ignores. The
    // service enforces it and the message comes back to the form.
    $order = app(PurchaseService::class)->createOrder(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new PoLine($this->item->uuid, 10, '12.00')],
    );
    $line = PoItem::first();

    $this->actingAs($this->staff)->post(route('purchasing.orders.receive', $order), [
        'lines' => [[
            'po_item_uuid' => $line->uuid, 'quantity' => 11,
            'batch_number' => 'B-1', 'expiry_date' => '2027-01-01',
        ]],
    ])->assertSessionHasErrors('lines');

    expect(Purchase::count())->toBe(0)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(0);
});

it('requires a batch number and expiry on a line being received', function () {
    // Only on a line actually arriving - most rows on a partial delivery are
    // left empty, so a blanket required rule would be wrong.
    $order = app(PurchaseService::class)->createOrder(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new PoLine($this->item->uuid, 10, '12.00')],
    );
    $line = PoItem::first();

    $this->actingAs($this->staff)->post(route('purchasing.orders.receive', $order), [
        'lines' => [['po_item_uuid' => $line->uuid, 'quantity' => 5]],
    ])->assertSessionHasErrors('lines');

    expect(Purchase::count())->toBe(0);
});

it('returns a duplicate batch number to the form and rolls the receipt back', function () {
    $order = app(PurchaseService::class)->createOrder(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new PoLine($this->item->uuid, 20, '12.00')],
    );
    $line = PoItem::first();

    $this->actingAs($this->staff)->post(route('purchasing.orders.receive', $order), [
        'lines' => [[
            'po_item_uuid' => $line->uuid, 'quantity' => 5,
            'batch_number' => 'B-DUP', 'expiry_date' => '2027-01-01',
        ]],
    ])->assertRedirect();

    // ItemService rejects the duplicate mid-receipt, and the whole thing rolls
    // back rather than leaving half a delivery on the shelf.
    $this->actingAs($this->staff)->post(route('purchasing.orders.receive', $order), [
        'lines' => [[
            'po_item_uuid' => $line->uuid, 'quantity' => 5,
            'batch_number' => 'B-DUP', 'expiry_date' => '2028-01-01',
        ]],
    ])->assertSessionHasErrors('lines');

    expect(Purchase::count())->toBe(1)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(5)
        ->and(PoItem::first()->quantity_received)->toBe(5);
});

it('takes the delivered cost over the ordered cost', function () {
    // Suppliers change a price between order and delivery, and the shop owes
    // what was invoiced rather than what was quoted.
    $order = app(PurchaseService::class)->createOrder(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new PoLine($this->item->uuid, 10, '12.00')],
    );
    $line = PoItem::first();

    $this->actingAs($this->staff)->post(route('purchasing.orders.receive', $order), [
        'lines' => [[
            'po_item_uuid' => $line->uuid, 'quantity' => 10,
            'batch_number' => 'B-1', 'expiry_date' => '2027-01-01',
            'unit_cost' => '13.50',
        ]],
    ])->assertRedirect();

    expect(Purchase::first()->total_amount)->toBe('135.00')
        // The batch carries what was actually paid, so valuation stays right.
        ->and(ItemBatch::first()->purchase_price)->toBe('13.50');
});

// ---------------------------------------------------------------------------
// direct receipts
// ---------------------------------------------------------------------------

it('records a delivery with no order behind it', function () {
    $this->actingAs($this->staff)->post(route('purchasing.receipts.store'), [
        'supplier_uuid' => $this->supplier->uuid,
        'invoice_reference' => 'INV-771',
        'lines' => [[
            'item_uuid' => $this->item->uuid,
            'quantity' => 25,
            'batch_number' => 'B-WALKIN',
            'expiry_date' => '2027-08-01',
            'unit_cost' => '10.00',
        ]],
    ])->assertRedirect();

    $receipt = Purchase::first();

    expect($receipt->po_uuid)->toBeNull()
        ->and($receipt->total_amount)->toBe('250.00')
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(25);
});

it('requires a batch and expiry on a direct receipt', function () {
    $this->actingAs($this->staff)->post(route('purchasing.receipts.store'), [
        'supplier_uuid' => $this->supplier->uuid,
        'lines' => [['item_uuid' => $this->item->uuid, 'quantity' => 5, 'unit_cost' => '1']],
    ])->assertSessionHasErrors('lines');

    expect(Purchase::count())->toBe(0);
});

it('refuses a direct receipt with no usable lines', function () {
    $this->actingAs($this->staff)->post(route('purchasing.receipts.store'), [
        'supplier_uuid' => $this->supplier->uuid,
        'lines' => [['item_uuid' => '', 'quantity' => 0]],
    ])->assertSessionHasErrors('lines');
});

// ---------------------------------------------------------------------------
// the screens render
// ---------------------------------------------------------------------------

it('renders every purchasing screen', function () {
    $order = app(PurchaseService::class)->createOrder(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new PoLine($this->item->uuid, 10, '12.00')],
    );

    $receipt = app(PurchaseService::class)->receiveDirect(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new DirectLine($this->item->uuid, 'B-X', '2027-01-01', 5, '10.00')],
    );

    $this->actingAs($this->staff);

    foreach ([
        route('suppliers.index'),
        route('suppliers.create'),
        route('suppliers.show', $this->supplier),
        route('suppliers.edit', $this->supplier),
        route('purchasing.orders.index'),
        route('purchasing.orders.create'),
        route('purchasing.orders.show', $order),
        route('purchasing.orders.receive.form', $order),
        route('purchasing.receipts.index'),
        route('purchasing.receipts.create'),
        route('purchasing.receipts.show', $receipt),
    ] as $url) {
        $this->get($url)->assertOk();
    }
});

it('marks a direct receipt as having no order', function () {
    app(PurchaseService::class)->receiveDirect(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new DirectLine($this->item->uuid, 'B-X', '2027-01-01', 5, '10.00')],
    );

    $this->actingAs($this->staff)->get(route('purchasing.receipts.index'))
        ->assertOk()
        ->assertSee('direct');
});

it('shows the expiry of each received batch on the receipt', function () {
    // The reason a receipt is worth reading later: which lot arrived, and when
    // it goes out of date.
    $receipt = app(PurchaseService::class)->receiveDirect(
        supplierUuid: $this->supplier->uuid, deviceId: DEVICE,
        lines: [new DirectLine($this->item->uuid, 'B-TRACE', '2027-11-01', 5, '10.00')],
    );

    $this->actingAs($this->staff)->get(route('purchasing.receipts.show', $receipt))
        ->assertOk()
        ->assertSee('B-TRACE')
        ->assertSee('1 Nov 2027');
});
