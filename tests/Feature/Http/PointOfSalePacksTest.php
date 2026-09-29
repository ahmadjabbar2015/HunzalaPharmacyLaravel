<?php

/*
 * The pack button and the item discount ceiling, at the till.
 *
 * PackAndDiscountLimitTest covers the services. This covers the POS side of the
 * same feature, which arrived without Livewire tests - and it is on the money
 * path, where the component decides what rate and what quantity reach
 * SaleService.
 *
 * The property that matters most: a customer buying a box and a customer buying
 * ten singles are charged the same, because the cart works in pieces at the
 * piece rate either way.
 */

use App\Livewire\PointOfSale;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\PinService;
use App\Services\StockService;
use Livewire\Livewire;

beforeEach(function () {
    $this->stock = app(StockService::class);

    $this->staff = User::factory()->create(['username' => 'ahmed']);
    app(PinService::class)->setPin($this->staff, '1111');

    $this->manager = User::factory()->manager()->create(['username' => 'bilal']);
    app(PinService::class)->setPin($this->manager, '2222');

    // A box of 20 tablets at 200.00 the box, so 10.00 the tablet.
    $this->packed = Item::factory()->create([
        'item_code' => 'PANADOL-20',
        'item_name' => 'Panadol 500mg (box of 20)',
        'sales_price' => '200.00',
        'pack_size' => 20,
        'unit_of_measure' => 'tablet',
    ]);

    $this->batch = ItemBatch::factory()->for($this->packed, 'item')->create();
    $this->stock->receive(itemUuid: $this->packed->uuid, batchUuid: $this->batch->uuid, quantity: 200, deviceId: DEVICE);

    $this->actingAs($this->staff);
});

/** The single cart line, whatever its generated id. */
function onlyLine($component): array
{
    return collect($component->get('cart'))->first();
}

// ---------------------------------------------------------------------------
// adding a single piece
// ---------------------------------------------------------------------------

it('adds one piece at the piece rate', function () {
    // Pressing the row itself sells a single tablet, not the box.
    $component = Livewire::test(PointOfSale::class)->call('addItem', $this->packed->uuid);

    expect(onlyLine($component)['quantity'])->toBe(1)
        ->and(onlyLine($component)['rate'])->toBe('10.00')
        ->and($component->get('subtotal'))->toBe('10.00');
});

// ---------------------------------------------------------------------------
// adding a whole pack
// ---------------------------------------------------------------------------

it('adds a whole pack as pack_size pieces at the piece rate', function () {
    /*
     * The quantity is in PIECES and the rate is per PIECE - not one line at the
     * pack rate. That is what keeps the stock movement and the money agreeing
     * with each other: the ledger counts tablets, so the cart must too.
     */
    $component = Livewire::test(PointOfSale::class)->call('addPack', $this->packed->uuid);

    expect(onlyLine($component)['quantity'])->toBe(20)
        ->and(onlyLine($component)['rate'])->toBe('10.00')
        ->and($component->get('subtotal'))->toBe('200.00');
});

it('charges a box and twenty singles the same', function () {
    // The whole point of pricing per piece. If these ever diverged, a customer
    // would be better or worse off depending on which button staff pressed.
    $box = Livewire::test(PointOfSale::class)->call('addPack', $this->packed->uuid);

    $singles = Livewire::test(PointOfSale::class);
    foreach (range(1, 20) as $ignored) {
        $singles->call('addItem', $this->packed->uuid);
    }

    expect($box->get('subtotal'))->toBe($singles->get('subtotal'))
        ->and($box->get('subtotal'))->toBe('200.00');
});

it('merges a pack into an existing line of the same item', function () {
    // Same item, same batch, same rate, so it accumulates rather than repeating.
    $component = Livewire::test(PointOfSale::class)
        ->call('addItem', $this->packed->uuid)
        ->call('addPack', $this->packed->uuid)
        ->assertCount('cart', 1);

    expect(onlyLine($component)['quantity'])->toBe(21)
        ->and($component->get('subtotal'))->toBe('210.00');
});

it('carries the pack size on the line', function () {
    // So the cart can show "2 packs + 3" without a query per row while someone
    // is typing into the till.
    $component = Livewire::test(PointOfSale::class)->call('addPack', $this->packed->uuid);

    expect(onlyLine($component)['pack_size'])->toBe(20)
        ->and(onlyLine($component)['unit'])->toBe('tablet');
});

it('treats a pack of one as a single piece', function () {
    $loose = Item::factory()->create(['item_code' => 'SYRUP', 'sales_price' => '85.00', 'pack_size' => 1]);
    $batch = ItemBatch::factory()->for($loose, 'item')->create();
    $this->stock->receive(itemUuid: $loose->uuid, batchUuid: $batch->uuid, quantity: 10, deviceId: DEVICE);

    $component = Livewire::test(PointOfSale::class)->call('addPack', $loose->uuid);

    expect(onlyLine($component)['quantity'])->toBe(1)
        ->and(onlyLine($component)['rate'])->toBe('85.00');
});

it('uses an overriding piece price over the division', function () {
    // Shops round a per-tablet price up: 8.33 a tablet is sold at 10.
    $this->packed->forceFill(['retail_price' => '12.00'])->save();

    $component = Livewire::test(PointOfSale::class)->call('addPack', $this->packed->uuid);

    expect(onlyLine($component)['rate'])->toBe('12.00')
        // 20 x 12.00, not the 200.00 pack price.
        ->and($component->get('subtotal'))->toBe('240.00');
});

// ---------------------------------------------------------------------------
// the pack sale reaches the ledger in pieces
// ---------------------------------------------------------------------------

it('records a pack sale as pieces in the ledger', function () {
    Livewire::test(PointOfSale::class)
        ->call('addPack', $this->packed->uuid)
        ->set('pin', '1111')
        ->call('save')
        ->assertHasNoErrors();

    $sale = Sale::first();
    $ledger = StockTransaction::where('reference_uuid', $sale->uuid)->first();

    expect($sale->net_amount)->toBe('200.00')
        ->and($sale->lines()->first()->quantity)->toBe(20)
        ->and($sale->lines()->first()->rate)->toBe('10.00')
        // One movement of 20 tablets, not one of "1 pack".
        ->and($ledger->qty_change)->toBe(-20)
        ->and($this->stock->quantityFor($this->packed->uuid))->toBe(180);
});

// ---------------------------------------------------------------------------
// the item discount ceiling
// ---------------------------------------------------------------------------

it('allows a discount inside the item ceiling', function () {
    $this->packed->forceFill(['max_discount_percent' => '10.00'])->save();

    $component = Livewire::test(PointOfSale::class)
        ->call('addPack', $this->packed->uuid)      // 200.00
        ->set('discountAmount', '15');              // under 10% of 200

    expect($component->get('overItemDiscountLimit'))->toBeFalse();

    $component->set('pin', '1111')->call('save')->assertHasNoErrors();

    expect(Sale::first()->discount_amount)->toBe('15.00');
});

it('demands a manager for a discount over the item ceiling', function () {
    $this->packed->forceFill(['max_discount_percent' => '10.00'])->save();

    $component = Livewire::test(PointOfSale::class)
        ->call('addPack', $this->packed->uuid)      // 200.00, so the ceiling is 20.00
        ->set('discountAmount', '50');

    expect($component->get('overItemDiscountLimit'))->toBeTrue()
        // This is the case a rupee threshold never catches: 50 off a cheap box
        // is a quarter of it, but nowhere near a large sum of money.
        ->and($component->get('needsAuthorisation'))->toBeTrue();

    $component->set('pin', '1111')->call('save')->assertHasErrors('authorisingPin');

    expect(Sale::count())->toBe(0);
});

it('lets a manager PIN pass a discount over the item ceiling', function () {
    $this->packed->forceFill(['max_discount_percent' => '10.00'])->save();

    Livewire::test(PointOfSale::class)
        ->call('addPack', $this->packed->uuid)
        ->set('discountAmount', '50')
        ->set('discountReason', 'damaged box, agreed with the customer')
        ->set('pin', '1111')
        ->set('authorisingPin', '2222')
        ->call('save')
        ->assertHasNoErrors();

    $sale = Sale::first();

    expect($sale->discount_amount)->toBe('50.00')
        ->and($sale->discount_pin_verified)->toBeTrue()
        // Still attributed to whoever served, not whoever authorised.
        ->and($sale->staff_member_uuid)->toBe($this->staff->uuid);
});

it('never discounts an item capped at zero without a manager', function () {
    // Zero is a real instruction - "this one never goes down in price" - which is
    // what a shop wants on narcotics and anything sold at cost.
    $this->packed->forceFill(['max_discount_percent' => '0.00'])->save();

    $component = Livewire::test(PointOfSale::class)
        ->call('addPack', $this->packed->uuid)
        ->set('discountAmount', '1');

    expect($component->get('itemDiscountCeiling'))->toBe('0.00')
        ->and($component->get('overItemDiscountLimit'))->toBeTrue();

    $component->set('pin', '1111')->call('save')->assertHasErrors('authorisingPin');
});

it('leaves an uncapped cart to the rupee threshold alone', function () {
    // null means NO ceiling, not a ceiling of zero. Every item in the catalogue
    // is uncapped by default, so getting this backwards would ban every discount
    // in the shop.
    Setting::create(['discount_pin_threshold' => '100.00', 'origin_device_id' => DEVICE]);

    $component = Livewire::test(PointOfSale::class)
        ->call('addPack', $this->packed->uuid)
        ->set('discountAmount', '50');

    expect($component->get('itemDiscountCeiling'))->toBeNull()
        ->and($component->get('overItemDiscountLimit'))->toBeFalse()
        // Under the 100.00 rupee threshold, so no manager needed either.
        ->and($component->get('needsAuthorisation'))->toBeFalse();

    $component->set('pin', '1111')->call('save')->assertHasNoErrors();
});

it('sums the ceiling across a mixed cart', function () {
    /*
     * A sale-level discount against a cart holding one capped and one uncapped
     * item may give away all of the second and a tenth of the first. Spreading it
     * any other way would either punish the uncapped item or let the capped one
     * be discounted past its limit by hiding behind the basket.
     */
    $this->packed->forceFill(['max_discount_percent' => '10.00'])->save();   // 200.00 -> 20.00

    $uncapped = Item::factory()->create(['item_code' => 'FREE', 'sales_price' => '100.00', 'pack_size' => 1]);
    $batch = ItemBatch::factory()->for($uncapped, 'item')->create();
    $this->stock->receive(itemUuid: $uncapped->uuid, batchUuid: $batch->uuid, quantity: 10, deviceId: DEVICE);

    $component = Livewire::test(PointOfSale::class)
        ->call('addPack', $this->packed->uuid)
        ->call('addItem', $uncapped->uuid);

    // 20.00 from the capped line + 100.00 from the uncapped one.
    expect($component->get('itemDiscountCeiling'))->toBe('120.00');

    $component->set('discountAmount', '120');
    expect($component->get('overItemDiscountLimit'))->toBeFalse();

    $component->set('discountAmount', '121');
    expect($component->get('overItemDiscountLimit'))->toBeTrue();
});

it('has no ceiling to speak of for an empty cart', function () {
    // Guards a divide-by-nothing on the first render, before anything is scanned.
    expect(Livewire::test(PointOfSale::class)->get('itemDiscountCeiling'))->toBeNull();
});

// ---------------------------------------------------------------------------
// the screen itself
// ---------------------------------------------------------------------------

it('offers a pack button only for an item sold in packs', function () {
    Item::factory()->create([
        'item_code' => 'SYRUP', 'item_name' => 'Cough Syrup', 'sales_price' => '85.00', 'pack_size' => 1,
    ]);

    // Asserted on the wire:click rather than the label, because the label is
    // split across markup ("+ pack" then "of 20" in a span) and matching the
    // visible text would pass or fail on how the button happens to be laid out.
    Livewire::test(PointOfSale::class)
        ->set('search', 'Panadol')
        ->assertSee("addPack('{$this->packed->uuid}')", escape: false)
        ->set('search', 'Cough')
        ->assertDontSee('addPack(', escape: false);
});

it('renders the till with a packed item in the cart', function () {
    // The regression that broke this screen entirely: the pack button was added
    // inside the row's own button, and the rewrite left six lines of the old
    // markup behind - an unbalanced @endif that stopped the template compiling.
    Livewire::test(PointOfSale::class)
        ->call('addPack', $this->packed->uuid)
        ->assertOk()
        ->assertSee('Panadol');
});
