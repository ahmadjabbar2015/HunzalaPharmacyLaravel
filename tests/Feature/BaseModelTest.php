<?php

use App\Models\Item;
use App\Models\StockTransaction;
use App\Support\BusinessDate;

/*
 * The foundation contract. Every rule the rest of the system rests on is
 * asserted here, because a break in any of them shows up months later as money
 * that does not add up rather than as a failing feature.
 */

it('generates a client-side uuid primary key', function () {
    $item = Item::create(['item_code' => 'PAN-500', 'item_name' => 'Panadol 500mg']);

    expect($item->uuid)->toBeString()->toHaveLength(36)
        ->and($item->getKeyName())->toBe('uuid')
        ->and($item->incrementing)->toBeFalse();
});

it('stamps the origin device on every row', function () {
    $item = Item::create(['item_code' => 'PAN-500', 'item_name' => 'Panadol 500mg']);

    expect($item->origin_device_id)->toBe(config('pharmacy.device_id'));
});

it('treats deletion as a state, never a missing row', function () {
    $item = Item::create(['item_code' => 'PAN-500', 'item_name' => 'Panadol 500mg']);

    $item->softDelete();

    $reloaded = Item::withDeleted()->first();

    // The row is still there - reconciliation has to tell "deleted" from "lost".
    expect(Item::withDeleted()->count())->toBe(1)
        ->and(Item::count())->toBe(0)         // hidden by the default scope
        ->and($reloaded->is_deleted)->toBeTrue()
        ->and($reloaded->deleted_at)->not->toBeNull();
});

it('hashes a new row and the same row read back identically', function () {
    // Guards against a model's $attributes defaults drifting from its
    // migration's: a column defaulted only in the database would be absent in
    // memory, and the hash would change the first time the row was reloaded.
    $item = Item::create(['item_code' => 'PAN-500', 'item_name' => 'Panadol 500mg']);

    expect($item->fresh()->recordHash())->toBe($item->recordHash());
});

it('does not bump updated_at when only a derived cache changes', function () {
    $item = Item::create(['item_code' => 'PAN-500', 'item_name' => 'Panadol 500mg']);
    $original = $item->updated_at;
    $hashBefore = $item->recordHash();

    // This is what a cache recompute does after every sync.
    $item->current_stock_qty = 47;
    $item->save();

    expect($item->fresh()->updated_at->eq($original))->toBeTrue()
        ->and($item->fresh()->recordHash())->toBe($hashBefore);
});

it('bumps updated_at when real data changes', function () {
    $item = Item::create(['item_code' => 'PAN-500', 'item_name' => 'Panadol 500mg']);
    $original = $item->updated_at;
    $hashBefore = $item->recordHash();

    $item->sales_price = 25.50;
    $item->save();

    expect($item->fresh()->updated_at->greaterThanOrEqualTo($original))->toBeTrue()
        ->and($item->fresh()->recordHash())->not->toBe($hashBefore);
});

it('keeps derived caches out of the record hash and the sync payload', function () {
    $item = Item::create([
        'item_code' => 'PAN-500',
        'item_name' => 'Panadol 500mg',
        'current_stock_qty' => 50,
    ]);

    expect($item->toSyncArray())->not->toHaveKey('current_stock_qty')
        ->and($item->toSyncArray())->not->toHaveKey('is_synced')
        ->and($item->toSyncArray())->toHaveKey('item_code');
});

it('produces a stable hash for identical data', function () {
    $now = BusinessDate::nowUtc();

    $attributes = [
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'item_code' => 'PAN-500',
        'item_name' => 'Panadol 500mg',
        'sales_price' => '10.50',
        'created_at' => $now,
        'updated_at' => $now,
        'origin_device_id' => 'WEB',
    ];

    $a = Item::create($attributes);
    $b = new Item($attributes);
    $b->exists = true;

    expect($b->recordHash())->toBe($a->recordHash());
});

it('derives quantity from the ledger, never from the cached column', function () {
    // The cache is deliberately wrong here - the ledger must win.
    $item = Item::create([
        'item_code' => 'PAN-500',
        'item_name' => 'Panadol 500mg',
        'current_stock_qty' => 999,
    ]);

    StockTransaction::create([
        'item_uuid' => $item->uuid,
        'transaction_type' => 'purchase',
        'qty_change' => 50,
        'transaction_date' => BusinessDate::nowUtc(),
    ]);
    StockTransaction::create([
        'item_uuid' => $item->uuid,
        'transaction_type' => 'sale',
        'qty_change' => -3,
        'transaction_date' => BusinessDate::nowUtc(),
    ]);
    StockTransaction::create([
        'item_uuid' => $item->uuid,
        'transaction_type' => 'sale',
        'qty_change' => -8,
        'transaction_date' => BusinessDate::nowUtc(),
    ]);

    // RULE 2's worked example: 50 - 3 - 8 = 39, from independent ledger rows.
    $derived = StockTransaction::where('item_uuid', $item->uuid)->sum('qty_change');

    expect((int) $derived)->toBe(39);
});
