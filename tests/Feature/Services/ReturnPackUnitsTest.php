<?php

/*
 * Returning an item that is sold in packs.
 *
 * ReturnService has no pack logic in it at all, and that is the point worth
 * protecting: a return works from the SALE LINE's own rate and quantity, both of
 * which are already per piece. Adding pack awareness here would be the mistake -
 * it would give the refund a second unit to disagree with the ledger about.
 *
 * So these tests assert an absence: that returns stayed unit-agnostic, and that
 * the cap is counted in the same pieces the sale was.
 */

use App\Exceptions\ReturnException;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\CartLine;
use App\Services\ReturnLine;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

beforeEach(function () {
    $this->returns = app(ReturnService::class);
    $this->stock = app(StockService::class);
    $this->staff = User::factory()->create(['username' => 'ahmed']);

    // A box of 20 at 600.00 the box, so 30.00 the tablet.
    $this->item = Item::factory()->create([
        'item_code' => 'PACKED-20',
        'pack_size' => 20,
        'sales_price' => '600.00',
        'unit_of_measure' => 'tablet',
    ]);

    $this->batch = ItemBatch::factory()->for($this->item, 'item')->create();
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 200, deviceId: DEVICE);

    // A customer buys one box: 20 tablets at 30.00.
    $this->sale = app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 20, $this->item->piecePrice(), $this->batch->uuid)],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    );

    $this->saleLine = $this->sale->lines()->first();
});

it('refunds a whole pack at the piece rate', function () {
    // 20 tablets at 30.00 back = 600.00, which is exactly what was paid.
    $return = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 20)],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    );

    expect($return->total_amount)->toBe('600.00')
        ->and($return->lines()->first()->rate)->toBe('30.00')
        ->and($return->lines()->first()->quantity)->toBe(20);
});

it('refunds part of a pack', function () {
    /*
     * A customer opening a box and bringing back four tablets is an ordinary
     * pharmacy return. Nothing about the pack makes this a special case,
     * because the sale line was always counted in tablets.
     */
    $return = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 4)],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    );

    expect($return->total_amount)->toBe('120.00')      // 4 x 30.00
        // 200 received, 20 sold, 4 back.
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(184);
});

it('returns pieces to the ledger, not packs', function () {
    $return = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 20)],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    );

    $ledger = StockTransaction::where('reference_uuid', $return->uuid)->first();

    // One movement of +20 tablets. A movement of +1 "pack" would leave the shelf
    // nineteen short while every screen showed it restocked.
    expect($ledger->qty_change)->toBe(20)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(200)
        ->and($this->stock->batchQuantityFor($this->batch->uuid))->toBe(200);
});

it('caps the return in pieces, not packs', function () {
    // 20 tablets were sold, so 21 is refused - the cap counts the same unit the
    // sale did, and never converts.
    expect(fn () => $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 21)],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    ))->toThrow(ReturnException::class);
});

it('counts earlier partial returns of a pack against the cap', function () {
    // The cumulative cap - the bug fixed in the Python port - holds for packed
    // items too, because it was never expressed in packs to begin with.
    $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 15)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    expect(fn () => $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 6)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(ReturnException::class);

    // The remaining five are still allowed.
    $second = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 5)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    expect($second->total_amount)->toBe('150.00')
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(200);
});

it('reports what remains returnable in pieces', function () {
    $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 8)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    $row = $this->returns->returnableItems($this->sale->uuid)->first();

    expect($row['returned'])->toBe(8)
        ->and($row['returnable'])->toBe(12);
});

it('refunds at the sold rate after the pack price changes', function () {
    // The shop repricing the box must not change what an earlier customer is
    // owed. The sale line's rate is the record of what they paid.
    $this->item->forceFill(['sales_price' => '900.00'])->save();

    $return = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 20)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    expect($return->total_amount)->toBe('600.00');
});
