<?php

/*
 * Pack size, per-piece pricing, and the per-item discount ceiling.
 *
 * Two things are being protected here. One is the unit: stock is counted in
 * PIECES everywhere, and every conversion from packs happens at the edge, so a
 * shelf of 50 tablets is never recorded as 50 boxes. The other is the ceiling:
 * the limit a counter cannot exceed without a manager, which is the reason the
 * field exists at all.
 */

use App\Exceptions\ItemException;
use App\Exceptions\SaleException;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\User;
use App\Services\CartLine;
use App\Services\ItemService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Support\Money;

beforeEach(function () {
    $this->sales = app(SaleService::class);
    $this->items = app(ItemService::class);
    $this->stock = app(StockService::class);
    $this->staff = User::factory()->create(['username' => 'ahmed']);
});

// ---------------------------------------------------------------------------
// pricing
// ---------------------------------------------------------------------------

it('splits the pack price across the pieces in the pack', function () {
    $item = Item::factory()->packOf(10)->create(['sales_price' => '300.00', 'purchase_price' => '250.00']);

    expect($item->piecePrice())->toBe('30.00')
        ->and($item->pieceCost())->toBe('25.00')
        ->and($item->packPrice())->toBe('300.00');
});

it('rounds a price that does not divide evenly, once', function () {
    // 250 across 30 tablets is 8.333... The shelf label says 8.33, and the
    // rounding happens once rather than per tablet.
    $item = Item::factory()->packOf(30)->create(['sales_price' => '250.00']);

    expect($item->piecePrice())->toBe('8.33');
});

it('quotes the pack at what the till will actually charge', function () {
    /*
     * A pack of 7 at 300.00 is 42.86 a piece, and the till sells a pack as seven
     * pieces - so a box rings up as 300.02, not 300.00.
     *
     * packPrice() reports that rather than sales_price. Reporting 300.00 meant
     * the pack button's tooltip, the inventory list and the item page all quoted
     * a figure the till would not charge. Two paisa is immaterial; the shop's own
     * screens disagreeing about a price is not.
     */
    $item = Item::factory()->packOf(7)->create(['sales_price' => '300.00']);

    expect($item->piecePrice())->toBe('42.86')
        ->and($item->packPrice())->toBe('300.02');

    // And the other direction, where the rounding goes down.
    $short = Item::factory()->packOf(3)->create(['sales_price' => '100.00']);

    expect($short->packPrice())->toBe('99.99');
});

it('quotes an evenly dividing pack at exactly the pack price', function () {
    // The normal case, and every case a shop sets up deliberately: no surprise.
    $item = Item::factory()->packOf(20)->create(['sales_price' => '200.00']);

    expect($item->packPrice())->toBe('200.00')
        ->and($item->piecePrice())->toBe('10.00');
});

it('lets a set piece price override the division', function () {
    // The real reason this field exists: 8.33 a tablet is sold at 10, and the
    // pack price follows the piece price rather than the other way round -
    // otherwise ten singles and one box would ring up different totals.
    $item = Item::factory()->packOf(10)->create(['sales_price' => '83.30', 'retail_price' => '10.00']);

    expect($item->piecePrice())->toBe('10.00')
        ->and($item->packPrice())->toBe('100.00');
});

it('leaves an item with no pack unchanged', function () {
    // The compatibility case. Every item in the catalogue before this feature
    // has pack_size 1, and must price exactly as it did.
    $item = Item::factory()->create(['sales_price' => '45.00', 'purchase_price' => '30.00']);

    expect($item->pack_size)->toBe(1)
        ->and($item->piecePrice())->toBe('45.00')
        ->and($item->packPrice())->toBe('45.00')
        ->and($item->pieceCost())->toBe('30.00');
});

it('refuses a pack of nothing', function () {
    // A zero pack makes every per-piece price a division by zero.
    expect(fn () => $this->items->create(
        itemCode: 'ZERO-1', itemName: 'Broken', deviceId: DEVICE, packSize: 0,
    ))->toThrow(ItemException::class);
});

it('describes a piece count as packs plus loose', function () {
    $item = Item::factory()->packOf(10)->create(['unit_of_measure' => 'tablet']);

    expect($item->describePieces(34))->toBe('34 tablet (3 packs + 4)')
        ->and($item->describePieces(30))->toBe('30 tablet (3 packs)');
});

it('treats a blank pack size as one piece to a pack', function () {
    // "Nothing entered" is an ordinary answer at a counter, and it means the
    // item is sold one at a time - not that the form is incomplete.
    $this->actingAs(User::factory()->create());

    $this->post(route('inventory.store'), [
        'item_code' => 'BLANK-1',
        'item_name' => 'Sold singly',
        'purchase_price' => '40.00',
        'sales_price' => '50.00',
        'pack_size' => '',
    ])->assertRedirect();

    $item = Item::where('item_code', 'BLANK-1')->first();

    expect($item->pack_size)->toBe(1)
        // And the piece price is then the price as typed: pack price / 1.
        ->and($item->piecePrice())->toBe('50.00');
});

it('treats a blank pack size on an edit as one, not an error', function () {
    // Clearing the field is how someone says "this is not sold in packs after
    // all". Before this it failed validation, which reads as a broken form.
    $item = Item::factory()->packOf(20)->create(['sales_price' => '200.00']);
    $this->actingAs(User::factory()->create());

    $this->put(route('inventory.update', $item), [
        'item_name' => $item->item_name,
        'purchase_price' => $item->purchase_price,
        'sales_price' => '200.00',
        'pack_size' => '',
    ])->assertRedirect(route('inventory.show', $item));

    expect($item->fresh()->pack_size)->toBe(1)
        ->and($item->fresh()->piecePrice())->toBe('200.00');
});

it('leaves the pack size alone when the field is not submitted at all', function () {
    // Absent is not blank. A request that never mentions pack_size is not
    // saying anything about it, and flattening a pack of 20 to 1 on that basis
    // would be a silent repricing of the shelf.
    $item = Item::factory()->packOf(20)->create(['sales_price' => '200.00']);
    $this->actingAs(User::factory()->create());

    $this->put(route('inventory.update', $item), [
        'item_name' => 'Renamed',
        'purchase_price' => $item->purchase_price,
        'sales_price' => '200.00',
    ])->assertRedirect();

    expect($item->fresh()->pack_size)->toBe(20)
        ->and($item->fresh()->piecePrice())->toBe('10.00');
});

it('prices a piece as the pack price divided by the pieces in the pack', function () {
    // The rule, stated plainly, at both ends of the range.
    expect(Item::factory()->packOf(1)->create(['sales_price' => '75.00'])->piecePrice())->toBe('75.00')
        ->and(Item::factory()->packOf(4)->create(['sales_price' => '75.00'])->piecePrice())->toBe('18.75')
        ->and(Item::factory()->packOf(100)->create(['sales_price' => '75.00'])->piecePrice())->toBe('0.75');
});

// ---------------------------------------------------------------------------
// receiving
// ---------------------------------------------------------------------------

it('receives packs into the ledger as pieces', function () {
    $item = Item::factory()->packOf(20)->create(['purchase_price' => '400.00']);

    $this->actingAs(User::factory()->create());

    $this->post(route('inventory.receive', $item), [
        'batch_number' => 'B-1',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 5,
        'quantity_unit' => 'packs',
        'purchase_price' => '400.00',
        'price_unit' => 'packs',
    ])->assertRedirect();

    // 5 packs of 20. The ledger holds pieces, so it holds 100 - and the batch
    // cost is what one piece cost, so COGS stays in the same unit as the sale.
    expect($this->stock->quantityFor($item->uuid))->toBe(100)
        ->and(ItemBatch::where('item_uuid', $item->uuid)->value('purchase_price'))->toBe('20.00');
});

it('converts the quantity and the price units independently', function () {
    /*
     * The two units are separate questions and a supplier's invoice answers them
     * separately - "5 boxes" priced "per tablet" is an ordinary way for a
     * delivery note to read. Coupling them, or assuming one from the other, is
     * how a batch ends up wrong by a factor of pack_size while looking entirely
     * plausible on screen.
     */
    $item = Item::factory()->packOf(20)->create();
    $this->actingAs(User::factory()->create());

    // Packs of quantity, priced per piece.
    $this->post(route('inventory.receive', $item), [
        'batch_number' => 'B-MIXED-1',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 5, 'quantity_unit' => 'packs',
        'purchase_price' => '20.00', 'price_unit' => 'pieces',
    ])->assertRedirect();

    expect($this->stock->quantityFor($item->uuid))->toBe(100)
        ->and(ItemBatch::where('batch_number', 'B-MIXED-1')->value('purchase_price'))->toBe('20.00');

    // Pieces of quantity, priced per pack.
    $this->post(route('inventory.receive', $item), [
        'batch_number' => 'B-MIXED-2',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 40, 'quantity_unit' => 'pieces',
        'purchase_price' => '400.00', 'price_unit' => 'packs',
    ])->assertRedirect();

    expect($this->stock->quantityFor($item->uuid))->toBe(140)
        ->and(ItemBatch::where('batch_number', 'B-MIXED-2')->value('purchase_price'))->toBe('20.00');
});

it('defaults both units to pieces when neither is given', function () {
    // The safe direction to be wrong in. Defaulting to packs would multiply a
    // plain number by the pack size behind the typist's back, and a stock figure
    // twenty times too high is harder to notice than one twenty times too low.
    $item = Item::factory()->packOf(20)->create();
    $this->actingAs(User::factory()->create());

    $this->post(route('inventory.receive', $item), [
        'batch_number' => 'B-BARE',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 5,
        'purchase_price' => '20.00',
    ])->assertRedirect();

    expect($this->stock->quantityFor($item->uuid))->toBe(5);
});

it('refuses a unit it does not understand', function () {
    $item = Item::factory()->packOf(20)->create();
    $this->actingAs(User::factory()->create());

    $this->post(route('inventory.receive', $item), [
        'batch_number' => 'B-BAD',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 5, 'quantity_unit' => 'boxes',
        'purchase_price' => '20.00',
    ])->assertSessionHasErrors('quantity_unit');

    expect($this->stock->quantityFor($item->uuid))->toBe(0);
});

it('values a pack cost that does not divide evenly at the piece level', function () {
    /*
     * 100.00 for a pack of 3 is 33.33 a piece, so the batch values three pieces
     * at 99.99 rather than the 100.00 the shop actually paid. The penny lands in
     * valuation, not in cash - the supplier is still owed 100.00, which comes
     * from the purchase total and not from this figure.
     *
     * Documented rather than corrected: carrying a per-piece cost is what lets
     * COGS be computed in the same unit the sale is in, and a cost that only
     * makes sense per pack would have to be divided somewhere anyway.
     */
    $item = Item::factory()->packOf(3)->create();
    $this->actingAs(User::factory()->create());

    $this->post(route('inventory.receive', $item), [
        'batch_number' => 'B-ODD',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 1, 'quantity_unit' => 'packs',
        'purchase_price' => '100.00', 'price_unit' => 'packs',
    ])->assertRedirect();

    expect($this->stock->quantityFor($item->uuid))->toBe(3)
        ->and(ItemBatch::where('batch_number', 'B-ODD')->value('purchase_price'))->toBe('33.33');
});

// ---------------------------------------------------------------------------
// the discount ceiling
// ---------------------------------------------------------------------------

it('allows a discount inside every item limit', function () {
    $item = Item::factory()->discountCappedAt(10)->create(['sales_price' => '100.00']);

    $sale = $this->sales->create(
        lines: [new CartLine(itemUuid: $item->uuid, quantity: 1, rate: '100.00')],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
        discountType: 'fixed',
        discountAmount: '10.00',
    );

    expect($sale->net_amount)->toBe('90.00');
});

it('refuses a discount over the item limit', function () {
    $item = Item::factory()->discountCappedAt(10)->create(['sales_price' => '100.00']);

    expect(fn () => $this->sales->create(
        lines: [new CartLine(itemUuid: $item->uuid, quantity: 1, rate: '100.00')],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
        discountType: 'fixed',
        discountAmount: '10.01',
    ))->toThrow(SaleException::class);
});

it('lets a manager PIN override the item limit', function () {
    // The limit is a brake on what a counter can do unsupervised, not a rule the
    // owner cannot break - and the override is already audited.
    $item = Item::factory()->discountCappedAt(10)->create(['sales_price' => '100.00']);

    $sale = $this->sales->create(
        lines: [new CartLine(itemUuid: $item->uuid, quantity: 1, rate: '100.00')],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
        discountType: 'fixed',
        discountAmount: '50.00',
        discountPinVerified: true,
    );

    expect($sale->net_amount)->toBe('50.00');
});

it('treats a zero limit as never discount this one', function () {
    // 0 and null are different instructions, and this is the difference.
    $item = Item::factory()->discountCappedAt(0)->create(['sales_price' => '100.00']);

    expect(fn () => $this->sales->create(
        lines: [new CartLine(itemUuid: $item->uuid, quantity: 1, rate: '100.00')],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
        discountType: 'fixed',
        discountAmount: '1.00',
    ))->toThrow(SaleException::class);
});

it('leaves an uncapped cart governed by the PIN threshold alone', function () {
    // Nothing in the basket carries a limit: the behaviour from before this
    // feature existed, unchanged.
    $item = Item::factory()->create(['sales_price' => '100.00']);

    expect($this->sales->discountCeiling([
        new CartLine(itemUuid: $item->uuid, quantity: 1, rate: '100.00'),
    ]))->toBeNull();
});

it('sums the ceiling across a mixed cart', function () {
    // A capped item and an uncapped one. The uncapped line may be given away
    // entirely; the capped one may not - so the capped item cannot hide behind
    // the size of the rest of the basket.
    $capped = Item::factory()->discountCappedAt(10)->create();
    $free = Item::factory()->create();

    $ceiling = $this->sales->discountCeiling([
        new CartLine(itemUuid: $capped->uuid, quantity: 1, rate: '100.00'),
        new CartLine(itemUuid: $free->uuid, quantity: 2, rate: '50.00'),
    ]);

    // 10% of 100, plus all of 100.
    expect($ceiling)->toBe('110.00');
});

it('refuses a max discount outside 0 to 100', function () {
    expect(fn () => $this->items->create(
        itemCode: 'BAD-1', itemName: 'Bad', deviceId: DEVICE, maxDiscountPercent: 101,
    ))->toThrow(ItemException::class);
});

// ---------------------------------------------------------------------------
// money
// ---------------------------------------------------------------------------

it('divides money at full precision before rounding', function () {
    expect(Money::divide('100.00', 3))->toBe('33.33')
        ->and(Money::divide('100.00', 8))->toBe('12.50')
        // A zero divisor returns the amount rather than throwing: pack_size is
        // validated at >= 1 everywhere it is written.
        ->and(Money::divide('100.00', 0))->toBe('100.00');
});
