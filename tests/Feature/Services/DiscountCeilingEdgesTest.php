<?php

/*
 * The discount ceiling where two features meet.
 *
 * The POS cart is keyed by LINE rather than by item, so the same medicine can
 * sit in a cart twice at different rates - a price override on one line, full
 * price on the other. The ceiling is computed per line for that reason, and this
 * is the interaction nothing else covers: PackAndDiscountLimitTest builds carts
 * of distinct items, where per-line and per-item arithmetic agree and a bug in
 * either is invisible.
 */

use App\Exceptions\SaleException;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\User;
use App\Services\CartLine;
use App\Services\SaleService;
use App\Services\StockService;

beforeEach(function () {
    $this->sales = app(SaleService::class);
    $this->staff = User::factory()->create(['username' => 'ahmed']);
});

/** An item with stock, optionally capped at a discount percentage. */
function sellableItem(string $code, string $price, ?string $capPercent = null): Item
{
    $item = Item::factory()->create([
        'item_code' => $code,
        'sales_price' => $price,
        'max_discount_percent' => $capPercent,
    ]);

    $batch = ItemBatch::factory()->for($item, 'item')->create();

    app(StockService::class)->receive(
        itemUuid: $item->uuid, batchUuid: $batch->uuid, quantity: 500, deviceId: DEVICE
    );

    return $item;
}

it('counts a capped item once per line, not once per item', function () {
    /*
     * The same medicine twice: 100.00 at full price and 60.00 discounted on the
     * spot, both capped at 10%. The ceiling is 10.00 + 6.00 = 16.00.
     *
     * Counting it once per ITEM would give 10.00 - punishing the second line for
     * sharing an item code with the first - and de-duplicating by uuid is the
     * natural way to write this and the wrong one.
     */
    $item = sellableItem('CAPPED', '100.00', '10.00');

    $ceiling = $this->sales->discountCeiling([
        new CartLine($item->uuid, 1, '100.00'),
        new CartLine($item->uuid, 1, '60.00'),
    ]);

    expect($ceiling)->toBe('16.00');
});

it('scales the ceiling with the quantity on a line', function () {
    // Ten boxes capped at 10% may give away ten times as much as one box.
    $item = sellableItem('CAPPED', '100.00', '10.00');

    expect($this->sales->discountCeiling([new CartLine($item->uuid, 1, '100.00')]))->toBe('10.00')
        ->and($this->sales->discountCeiling([new CartLine($item->uuid, 10, '100.00')]))->toBe('100.00');
});

it('lets an uncapped line be given away entirely', function () {
    // Uncapped means no limit, so the whole line is available to a sale-level
    // discount - which is what makes a mixed cart's ceiling the sum it is.
    $free = sellableItem('FREE', '250.00');

    expect($this->sales->discountCeiling([new CartLine($free->uuid, 2, '250.00')]))->toBeNull();
});

it('caps a mixed cart at the sum of what each line allows', function () {
    $capped = sellableItem('CAPPED', '200.00', '10.00');     // 20.00
    $free = sellableItem('FREE', '100.00');                  // 100.00

    $ceiling = $this->sales->discountCeiling([
        new CartLine($capped->uuid, 1, '200.00'),
        new CartLine($free->uuid, 1, '100.00'),
    ]);

    expect($ceiling)->toBe('120.00');
});

it('gives a zero-capped line nothing while the rest of the cart still gives', function () {
    /*
     * Zero means "this one never goes down in price" - narcotics, anything sold
     * at cost. It must not drag the whole basket to zero, or a shop could never
     * discount anything in a cart that happened to contain one.
     */
    $never = sellableItem('NEVER', '500.00', '0.00');
    $free = sellableItem('FREE', '100.00');

    $ceiling = $this->sales->discountCeiling([
        new CartLine($never->uuid, 1, '500.00'),
        new CartLine($free->uuid, 1, '100.00'),
    ]);

    expect($ceiling)->toBe('100.00');
});

it('refuses a discount a paisa over the ceiling and allows one exactly on it', function () {
    // The boundary is inclusive: a discount exactly at the limit is within it.
    $item = sellableItem('CAPPED', '100.00', '10.00');
    $line = [new CartLine($item->uuid, 1, '100.00')];

    $this->sales->create(
        lines: $line, staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        discountType: 'fixed', discountAmount: '10.00',
    );

    expect(fn () => $this->sales->create(
        lines: $line, staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        discountType: 'fixed', discountAmount: '10.01',
    ))->toThrow(SaleException::class);
});

it('applies the ceiling to a percentage discount as well as a fixed one', function () {
    // The limit is on the RESOLVED rupee figure, so it cannot be walked around
    // by expressing the same giveaway as a percentage.
    $item = sellableItem('CAPPED', '100.00', '10.00');

    expect(fn () => $this->sales->create(
        lines: [new CartLine($item->uuid, 1, '100.00')],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        discountType: 'percentage', discountAmount: '25',   // 25.00, over the 10.00 cap
    ))->toThrow(SaleException::class);
});

it('lets a verified manager PIN pass the ceiling', function () {
    // The limit is a brake on what a counter can do unsupervised, not a rule the
    // owner cannot break - and the override is audited.
    $item = sellableItem('CAPPED', '100.00', '10.00');

    $sale = $this->sales->create(
        lines: [new CartLine($item->uuid, 1, '100.00')],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        discountType: 'fixed', discountAmount: '40.00',
        discountPinVerified: true,
    );

    expect($sale->discount_amount)->toBe('40.00')
        ->and($sale->net_amount)->toBe('60.00');
});

it('has no ceiling for a cart of only uncapped items', function () {
    // null, not zero. Every item in the catalogue is uncapped by default, so
    // returning a ceiling here would ban every discount in the shop.
    $a = sellableItem('A', '100.00');
    $b = sellableItem('B', '50.00');

    expect($this->sales->discountCeiling([
        new CartLine($a->uuid, 1, '100.00'),
        new CartLine($b->uuid, 1, '50.00'),
    ]))->toBeNull();
});
