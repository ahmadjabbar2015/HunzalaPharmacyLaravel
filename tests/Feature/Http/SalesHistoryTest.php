<?php

/*
 * Sales history and the receipt.
 *
 * Read-only by design: a sale is never edited, only returned against. These
 * tests exist partly to pin that - there is no edit route, and the figures on a
 * returned sale stay as they were.
 */

use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\PrintLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartLine;
use App\Services\ReturnLine;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

beforeEach(function () {
    $this->staff = User::factory()->create(['username' => 'ahmed', 'full_name' => 'Ahmed Ali']);

    $this->item = Item::factory()->create(['item_code' => 'PANADOL-500', 'item_name' => 'Panadol 500mg']);
    $this->batch = ItemBatch::factory()->for($this->item, 'item')->create(['batch_number' => 'B-1']);

    app(StockService::class)->receive(
        itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 100, deviceId: DEVICE
    );

    $this->sale = app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 2, '100.00', $this->batch->uuid)],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    );

    $this->actingAs($this->staff);
});

// ---------------------------------------------------------------------------
// the list
// ---------------------------------------------------------------------------

it('lists sales', function () {
    $this->get(route('sales.index'))
        ->assertOk()
        ->assertSee($this->sale->invoice_number)
        ->assertSee('Ahmed Ali');
});

it('filters by invoice number', function () {
    $other = app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 1, '50.00', $this->batch->uuid)],
        staffMemberUuid: $this->staff->uuid, deviceId: 'OTHER',
    );

    $this->get(route('sales.index', ['q' => $this->sale->invoice_number]))
        ->assertOk()
        ->assertSee($this->sale->invoice_number)
        ->assertDontSee($other->invoice_number);
});

it('filters by payment method', function () {
    app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 1, '75.00', $this->batch->uuid)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE, paymentMethod: 'mobile',
    );

    $this->get(route('sales.index', ['payment_method' => 'mobile']))
        ->assertOk()
        ->assertDontSee($this->sale->invoice_number);
});

it('totals the whole filter rather than the page', function () {
    // Someone filtering to a day wants that day's takings. A per-page figure
    // would silently answer a different question.
    app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 1, '300.00', $this->batch->uuid)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // 200.00 + 300.00
    $this->get(route('sales.index'))->assertOk()->assertSee('500.00');
});

it('rejects a filter value it does not understand', function () {
    $this->get(route('sales.index', ['payment_method' => 'barter']))
        ->assertSessionHasErrors('payment_method');
});

// ---------------------------------------------------------------------------
// the detail
// ---------------------------------------------------------------------------

it('shows a sale with its lines and batch', function () {
    $this->get(route('sales.show', $this->sale))
        ->assertOk()
        ->assertSee($this->sale->invoice_number)
        ->assertSee('Panadol 500mg')
        // The batch is on the line so a recall can be traced.
        ->assertSee('B-1')
        ->assertSee('200.00');
});

it('shows a return against the sale without changing the sale', function () {
    app(ReturnService::class)->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->sale->lines()->first()->uuid, 1)],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    );

    $response = $this->get(route('sales.show', $this->sale));

    // The return is listed, but the sale's own figures are untouched: the
    // history of what was sold stays true even after some came back.
    $response->assertOk()->assertSee('Returned against this sale')->assertSee('200.00');

    expect($this->sale->fresh()->net_amount)->toBe('200.00')
        ->and($this->sale->fresh()->is_returned)->toBeTrue();
});

// ---------------------------------------------------------------------------
// the receipt
// ---------------------------------------------------------------------------

it('renders a printable receipt', function () {
    Setting::create([
        'shop_name' => 'HunZala Pharmacy',
        'shop_phone' => '051-1234567',
        'receipt_width' => '80mm',
        'origin_device_id' => DEVICE,
    ]);

    $this->get(route('sales.receipt', $this->sale))
        ->assertOk()
        ->assertSee('HunZala Pharmacy')
        ->assertSee($this->sale->invoice_number)
        ->assertSee('Panadol 500mg')
        // The roll width comes from settings, because the printer decides it.
        ->assertSee('80mm', escape: false);
});

it('counts each print and logs it', function () {
    expect($this->sale->print_count)->toBe(0);

    $this->get(route('sales.receipt', $this->sale));
    $this->get(route('sales.receipt', $this->sale));

    // A reprint is legitimate - a customer asks for a copy - but it is also how
    // one sale could be shown to two people, so the count is part of the trail.
    expect($this->sale->fresh()->print_count)->toBe(2)
        ->and(PrintLog::where('reference_uuid', $this->sale->uuid)->count())->toBe(2)
        ->and(PrintLog::first()->printed_by_user_uuid)->toBe($this->staff->uuid);
});

it('marks a reprint on the receipt itself', function () {
    $this->get(route('sales.receipt', $this->sale));

    // So a reprint cannot be passed off as the original.
    $this->get(route('sales.receipt', $this->sale))->assertSee('REPRINT');
});

it('does not change the record hash when a receipt is printed', function () {
    /*
     * print_count is excluded from the record hash but IS synced: a reprint is a
     * real event the server wants, and not an edit to the sale. If printing
     * changed the hash, every receipt would look to a reconciler like the sale
     * had been altered.
     */
    $before = $this->sale->recordHash();

    $this->get(route('sales.receipt', $this->sale));

    $after = $this->sale->fresh();

    expect($after->print_count)->toBe(1)
        ->and($after->recordHash())->toBe($before);
});

it('renders a receipt with no settings row', function () {
    // A fresh deployment that has not been through the settings screen must
    // still be able to print.
    $this->get(route('sales.receipt', $this->sale))->assertOk();
});
