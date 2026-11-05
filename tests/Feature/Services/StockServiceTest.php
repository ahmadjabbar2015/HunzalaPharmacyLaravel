<?php

/*
 * Ported from tests/test_stock_service.py.
 *
 * The first service and the first suite: everything else in the system rests on
 * derived stock being right, so this is proved before any screen exists
 * (LARAVEL_PLAN.md §4).
 *
 * Two tests here have no Python counterpart - the N+1 pair at the bottom -
 * because LARAVEL_PLAN.md §5 names that trap specifically, and a trap named in
 * a plan but not covered by a test is a trap that gets built anyway.
 */

use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->stock = app(StockService::class);

    $this->item = Item::factory()->create([
        'item_code' => 'PANADOL-500',
        'item_name' => 'Panadol 500mg',
    ]);

    $this->batch = ItemBatch::factory()->for($this->item, 'item')->create([
        'batch_number' => 'B-2211',
        'expiry_date' => '2027-01-01',
    ]);
});

// ---------------------------------------------------------------------------
// derived quantity - the source of truth (RULE 2)
// ---------------------------------------------------------------------------

it('derives quantity by summing the ledger', function () {
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 50, deviceId: DEVICE);
    $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 3, deviceId: DEVICE);

    expect($this->stock->quantityFor($this->item->uuid))->toBe(47)
        ->and($this->stock->batchQuantityFor($this->batch->uuid))->toBe(47);
});

it('never trusts the cached column over the ledger', function () {
    // The exact failure this design exists to prevent: a cache that has drifted.
    // A wrong number in current_stock_qty must change nothing about the answer.
    $this->item->forceFill(['current_stock_qty' => 999])->save();

    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 10, deviceId: DEVICE);
    $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 3, deviceId: DEVICE);

    expect($this->stock->quantityFor($this->item->uuid))->toBe(7);
});

it('keeps the cache equal to the derived value after each write', function () {
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 50, deviceId: DEVICE);
    $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 8, deviceId: DEVICE);

    $this->item->refresh();
    $this->batch->refresh();

    expect($this->item->current_stock_qty)->toBe(42)
        ->and($this->batch->current_qty)->toBe(42)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(42);
});

it('sums two devices selling the same item offline', function () {
    /*
     * RULE 2's worked example. Panadol B-2211 starts at 50, the PC sells 3 and
     * the web till sells 8 during an outage. Both ledger rows land and survive,
     * so the answer is 39.
     *
     * Had either device pushed a quantity VALUE instead of a movement - 47 from
     * one, 42 from the other - whichever synced second would have silently won
     * and the shop would be wrong by five boxes with nothing to show why.
     */
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 50, deviceId: 'PC1');
    $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 3, deviceId: 'PC1');
    $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 8, deviceId: 'WEB');

    expect($this->stock->quantityFor($this->item->uuid))->toBe(39);

    $this->item->refresh();
    expect($this->item->current_stock_qty)->toBe(39);
});

it('returns zero for an item with no ledger rows', function () {
    expect($this->stock->quantityFor($this->item->uuid))->toBe(0);
});

// ---------------------------------------------------------------------------
// append-only guarantees
// ---------------------------------------------------------------------------

it('excludes soft-deleted rows from the sum', function () {
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 50, deviceId: DEVICE);
    $wrong = $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 5, deviceId: DEVICE);

    expect($this->stock->quantityFor($this->item->uuid))->toBe(45);

    // A correction is a soft-delete of the erroneous row: deletion is a state
    // that syncs, never a missing row (RULE 4).
    $wrong->softDelete();
    $this->stock->recomputeCache($this->item->uuid);

    expect($this->stock->quantityFor($this->item->uuid))->toBe(50);

    $this->item->refresh();
    expect($this->item->current_stock_qty)->toBe(50);
});

it('records a correction as a new row rather than editing the original', function () {
    $original = $this->stock->receive(
        itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 50, deviceId: DEVICE
    );

    $this->stock->adjust(
        itemUuid: $this->item->uuid,
        qtyChange: -2,
        reason: 'two boxes crushed in transit',
        deviceId: DEVICE,
        batchUuid: $this->batch->uuid,
    );

    expect($this->stock->quantityFor($this->item->uuid))->toBe(48)
        // The original row is untouched - the ledger is append-only.
        ->and($original->fresh()->qty_change)->toBe(50)
        ->and(StockTransaction::where('item_uuid', $this->item->uuid)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// input validation
// ---------------------------------------------------------------------------

it('requires a reason on an adjustment', function () {
    // Without this, "stock was wrong so I fixed it" leaves no record of why,
    // and an adjustment is exactly where fraud would hide.
    expect(fn () => $this->stock->adjust(
        itemUuid: $this->item->uuid, qtyChange: -2, reason: '  ', deviceId: DEVICE
    ))->toThrow(InvalidArgumentException::class);

    $this->stock->adjust(
        itemUuid: $this->item->uuid, qtyChange: -2, reason: 'expired, pulled from shelf', deviceId: DEVICE
    );

    expect($this->stock->quantityFor($this->item->uuid))->toBe(-2);
});

it('rejects a zero quantity change', function () {
    // A zero movement is never a real event; it is a bug in the caller.
    expect(fn () => $this->stock->record(
        itemUuid: $this->item->uuid, qtyChange: 0, transactionType: 'sale', deviceId: DEVICE
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects an unknown transaction type', function () {
    expect(fn () => $this->stock->record(
        itemUuid: $this->item->uuid, qtyChange: 1, transactionType: 'shrinkage', deviceId: DEVICE
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects a non-positive quantity on the convenience wrappers', function () {
    // The wrappers own the sign so call sites never have to. Passing a negative
    // to sell() means the caller is guessing, which is worth failing loudly.
    expect(fn () => $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 0, deviceId: DEVICE))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->stock->sell(itemUuid: $this->item->uuid, quantity: -1, deviceId: DEVICE))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->stock->returnToStock(itemUuid: $this->item->uuid, quantity: 0, deviceId: DEVICE))
        ->toThrow(InvalidArgumentException::class);
});

it('stores a sale as a negative movement and a return as a positive one', function () {
    $sale = $this->stock->sell(itemUuid: $this->item->uuid, quantity: 4, deviceId: DEVICE);
    $return = $this->stock->returnToStock(itemUuid: $this->item->uuid, quantity: 4, deviceId: DEVICE);

    expect($sale->qty_change)->toBe(-4)
        ->and($sale->transaction_type)->toBe('sale')
        ->and($return->qty_change)->toBe(4)
        ->and($return->transaction_type)->toBe('return')
        // A return of everything sold puts the item back where it started.
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(0);
});

it('stamps the origin device and the performing user on every row', function () {
    $user = User::factory()->create();

    $txn = $this->stock->receive(
        itemUuid: $this->item->uuid,
        batchUuid: $this->batch->uuid,
        quantity: 5,
        deviceId: 'PC7',
        performedByUserUuid: $user->uuid,
        reason: 'opening stock',
    );

    expect($txn->origin_device_id)->toBe('PC7')
        ->and($txn->performed_by_user_uuid)->toBe($user->uuid)
        ->and($txn->transaction_date)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// negative stock is a warning light, not a workflow
// ---------------------------------------------------------------------------

it('flags negative stock without blocking the sale', function () {
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 2, deviceId: DEVICE);

    // A deliberate oversell. The shelf stops a sale, not the database: if stock
    // says 2 and the shelf has 5, refusing the sale loses a real customer over
    // a data error. It is recorded and surfaced instead.
    $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 5, deviceId: DEVICE);

    expect($this->stock->quantityFor($this->item->uuid))->toBe(-3)
        ->and($this->stock->negativeStockItems())->toContain([
            'item_uuid' => $this->item->uuid,
            'qty' => -3,
        ]);
});

it('leaves a healthy item out of the negative-stock report', function () {
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 10, deviceId: DEVICE);

    expect($this->stock->negativeStockItems())->toBeEmpty();
});

// ---------------------------------------------------------------------------
// cache rebuild
// ---------------------------------------------------------------------------

it('rebuilds every cache from the ledger', function () {
    $other = Item::factory()->create();
    $otherBatch = ItemBatch::factory()->for($other, 'item')->create();

    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 20, deviceId: DEVICE);
    $this->stock->receive(itemUuid: $other->uuid, batchUuid: $otherBatch->uuid, quantity: 7, deviceId: DEVICE);

    // Corrupt both caches the way a bad restore or an interrupted sync would.
    DB::table('items')->update(['current_stock_qty' => -1]);
    DB::table('item_batches')->update(['current_qty' => -1]);

    expect($this->stock->recomputeAllCaches())->toBe(2);

    expect($this->item->fresh()->current_stock_qty)->toBe(20)
        ->and($other->fresh()->current_stock_qty)->toBe(7)
        ->and($this->batch->fresh()->current_qty)->toBe(20)
        ->and($otherBatch->fresh()->current_qty)->toBe(7);
});

it('reports which items disagree with the ledger', function () {
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 20, deviceId: DEVICE);

    expect($this->stock->cacheDrift())->toBeEmpty();

    // Write straight to the column, bypassing the service - which is the only
    // way this can happen, and is what the integrity screen looks for.
    DB::table('items')->where('uuid', $this->item->uuid)->update(['current_stock_qty' => 17]);

    expect($this->stock->cacheDrift())->toContain([
        'item_uuid' => $this->item->uuid,
        'cached' => 17,
        'derived' => 20,
    ]);
});

// ---------------------------------------------------------------------------
// record hash - the reconciliation foundation
// ---------------------------------------------------------------------------

it('does not change the record hash when only the cache moves', function () {
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 50, deviceId: DEVICE);

    $this->item->refresh();
    $beforeHash = $this->item->recordHash();
    $beforeTime = $this->item->updated_at;

    $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 5, deviceId: DEVICE);
    $this->item->refresh();

    expect($this->item->current_stock_qty)->toBe(45)                // the cache moved
        ->and($this->item->updated_at->eq($beforeTime))->toBeTrue() // updated_at did not
        ->and($this->item->recordHash())->toBe($beforeHash);        // so neither did the hash
});

// ---------------------------------------------------------------------------
// N+1 on derived stock - LARAVEL_PLAN.md §5's second named trap
// ---------------------------------------------------------------------------

it('totals many items in a single query', function () {
    $items = Item::factory()->count(20)->create();

    foreach ($items as $i => $item) {
        $batch = ItemBatch::factory()->for($item, 'item')->create();
        $this->stock->receive(itemUuid: $item->uuid, batchUuid: $batch->uuid, quantity: $i + 1, deviceId: DEVICE);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $quantities = $this->stock->quantitiesFor($items->pluck('uuid')->all());

    // One query regardless of how many items were asked about. A per-row SUM()
    // here is what makes a 500-item inventory page issue 500 queries.
    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($quantities[$items[0]->uuid])->toBe(1)
        ->and($quantities[$items[19]->uuid])->toBe(20);

    DB::disableQueryLog();
});

it('reports zero rather than omitting an item with no ledger rows', function () {
    // An item absent from the SUM's result set must come back as 0, not be
    // missing from the map - otherwise every caller needs a null check and one
    // of them will forget, rendering an empty cell instead of "0".
    $quantities = $this->stock->quantitiesFor([$this->item->uuid]);

    expect($quantities)->toHaveKey($this->item->uuid)
        ->and($quantities[$this->item->uuid])->toBe(0);
});
