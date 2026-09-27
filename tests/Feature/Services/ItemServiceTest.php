<?php

/*
 * Ported from tests/test_item_service.py.
 *
 * The catalogue and its batches. Receiving goods must move stock only through
 * the ledger, and the derived quantity must agree - so most of these tests check
 * StockService's answer rather than a column on the batch.
 */

use App\Exceptions\ItemException;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Services\ItemService;
use App\Services\StockService;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->items = app(ItemService::class);
    $this->stock = app(StockService::class);

    $this->item = $this->items->create(
        itemCode: 'PANADOL-500',
        itemName: 'Panadol 500mg',
        deviceId: DEVICE,
        salesPrice: '20.00',
        purchasePrice: '12.00',
    );
});

/** Receive a batch with the boilerplate the tests do not care about filled in. */
function receiveBatch(string $itemUuid, string $number, string $expiry, int $qty = 5): ItemBatch
{
    return app(ItemService::class)->receiveNewBatch(
        itemUuid: $itemUuid,
        batchNumber: $number,
        expiryDate: $expiry,
        quantity: $qty,
        purchasePrice: '1.00',
        deviceId: DEVICE,
    );
}

// ---------------------------------------------------------------------------
// catalogue
// ---------------------------------------------------------------------------

it('creates an item and lists it', function () {
    expect($this->item->item_code)->toBe('PANADOL-500')
        ->and($this->item->sales_price)->toBe('20.00')
        ->and($this->items->list()->pluck('item_code')->all())->toBe(['PANADOL-500']);
});

it('rejects a duplicate item code', function () {
    expect(fn () => $this->items->create(itemCode: 'PANADOL-500', itemName: 'Another', deviceId: DEVICE))
        ->toThrow(ItemException::class);
});

it('rejects a blank name or code', function () {
    expect(fn () => $this->items->create(itemCode: '  ', itemName: 'Valid', deviceId: DEVICE))
        ->toThrow(ItemException::class);

    expect(fn () => $this->items->create(itemCode: 'VALID', itemName: '  ', deviceId: DEVICE))
        ->toThrow(ItemException::class);
});

it('trims whitespace off the code and name', function () {
    $item = $this->items->create(itemCode: '  ASPIRIN  ', itemName: '  Aspirin 75mg  ', deviceId: DEVICE);

    // An untrimmed code would not match the one printed on a receipt, and
    // 'ASPIRIN ' and 'ASPIRIN' would both be creatable as separate items.
    expect($item->item_code)->toBe('ASPIRIN')
        ->and($item->item_name)->toBe('Aspirin 75mg');
});

// ---------------------------------------------------------------------------
// receiving goods
// ---------------------------------------------------------------------------

it('moves received stock through the ledger', function () {
    $batch = receiveBatch($this->item->uuid, 'B-1', '2027-01-01', 40);

    // The batch row records what arrived; the LEDGER is what makes it stock.
    expect($batch->received_qty)->toBe(40)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(40)
        ->and($this->stock->batchQuantityFor($batch->uuid))->toBe(40)
        ->and($batch->fresh()->current_qty)->toBe(40);

    $ledger = DB::table('stock_transactions')->where('batch_uuid', $batch->uuid)->first();
    expect($ledger->transaction_type)->toBe('purchase')
        ->and((int) $ledger->qty_change)->toBe(40);
});

it('requires an expiry date and a positive quantity', function () {
    // Expiry is mandatory because sales pick the batch expiring first. A batch
    // with no expiry could not be ordered, so it could never be sold.
    expect(fn () => $this->items->receiveNewBatch(
        itemUuid: $this->item->uuid, batchNumber: 'B-2', expiryDate: null,
        quantity: 5, purchasePrice: '1.00', deviceId: DEVICE,
    ))->toThrow(ItemException::class);

    expect(fn () => receiveBatch($this->item->uuid, 'B-3', '2027-01-01', 0))
        ->toThrow(ItemException::class);

    expect(fn () => receiveBatch($this->item->uuid, 'B-4', '2027-01-01', -5))
        ->toThrow(ItemException::class);
});

it('requires a batch number', function () {
    expect(fn () => receiveBatch($this->item->uuid, '   ', '2027-01-01'))
        ->toThrow(ItemException::class);
});

it('rejects a duplicate batch number for the same item', function () {
    receiveBatch($this->item->uuid, 'B-2211', '2027-01-01');

    // Two batches with one number cannot be told apart on a shelf or a recall.
    expect(fn () => receiveBatch($this->item->uuid, 'B-2211', '2028-01-01'))
        ->toThrow(ItemException::class);
});

it('allows the same batch number on a different item', function () {
    $other = $this->items->create(itemCode: 'BRUFEN', itemName: 'Brufen', deviceId: DEVICE);

    // Batch numbers come from manufacturers and collide across products; the
    // uniqueness that matters is per item.
    receiveBatch($this->item->uuid, 'B-100', '2027-01-01');
    receiveBatch($other->uuid, 'B-100', '2027-01-01');

    expect(ItemBatch::where('batch_number', 'B-100')->count())->toBe(2);
});

it('leaves no batch behind when the stock movement fails', function () {
    // The batch row and its opening ledger row are one transaction. A batch
    // with no ledger row would show stock of zero and look like a data bug.
    expect(fn () => $this->items->receiveNewBatch(
        itemUuid: 'no-such-item', batchNumber: 'B-9', expiryDate: '2027-01-01',
        quantity: 5, purchasePrice: '1.00', deviceId: DEVICE,
    ))->toThrow(Exception::class);

    expect(ItemBatch::where('batch_number', 'B-9')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// barcodes - the scan-and-go path
// ---------------------------------------------------------------------------

it('finds an item by exact barcode', function () {
    $item = $this->items->create(
        itemCode: 'DISPRIN', itemName: 'Disprin', deviceId: DEVICE, barcode: '8964000123456',
    );

    expect($this->items->findByBarcode('8964000123456')->uuid)->toBe($item->uuid);
});

it('returns nothing for a blank or unknown barcode', function () {
    expect($this->items->findByBarcode('   '))->toBeNull()
        ->and($this->items->findByBarcode('0000000000000'))->toBeNull();
});

it('rejects a barcode already assigned to another item', function () {
    $this->items->create(itemCode: 'A-1', itemName: 'A', deviceId: DEVICE, barcode: '111');

    // A shared barcode means a scan is ambiguous, and the till would pick one
    // silently - charging for the wrong medicine.
    expect(fn () => $this->items->create(itemCode: 'A-2', itemName: 'B', deviceId: DEVICE, barcode: '111'))
        ->toThrow(ItemException::class);
});

it('allows many items with no barcode', function () {
    // Most stock in the shop is unbarcoded, so null must not collide with null.
    $this->items->create(itemCode: 'N-1', itemName: 'No barcode 1', deviceId: DEVICE);
    $this->items->create(itemCode: 'N-2', itemName: 'No barcode 2', deviceId: DEVICE);

    expect(Item::whereNull('barcode')->count())->toBe(3);
});

it('excludes an inactive item from barcode lookup', function () {
    $item = $this->items->create(
        itemCode: 'OLD', itemName: 'Discontinued', deviceId: DEVICE, barcode: '999',
    );
    $this->items->update($item, DEVICE, ['is_active' => false]);

    expect($this->items->findByBarcode('999'))->toBeNull();
});

// ---------------------------------------------------------------------------
// search
// ---------------------------------------------------------------------------

it('needs two characters before searching', function () {
    // A one-character search matches most of the catalogue, and the POS would
    // render the whole shop on the first keystroke.
    expect($this->items->search('P'))->toBeEmpty()
        ->and($this->items->search(''))->toBeEmpty();
});

it('searches by name, code and barcode', function () {
    $this->items->create(
        itemCode: 'BRUFEN-400', itemName: 'Brufen 400mg', deviceId: DEVICE, barcode: '8964999',
    );

    expect($this->items->search('brufen')->pluck('item_code')->all())->toBe(['BRUFEN-400'])
        ->and($this->items->search('BRUFEN-4')->pluck('item_code')->all())->toBe(['BRUFEN-400'])
        ->and($this->items->search('8964999')->pluck('item_code')->all())->toBe(['BRUFEN-400'])
        // Case-insensitive: staff type in a hurry.
        ->and($this->items->search('PANADOL')->pluck('item_code')->all())->toBe(['PANADOL-500']);
});

it('excludes inactive items from search', function () {
    $this->items->update($this->item, DEVICE, ['is_active' => false]);

    expect($this->items->search('panadol'))->toBeEmpty();
});

// ---------------------------------------------------------------------------
// FEFO - first expiry, first out
// ---------------------------------------------------------------------------

it('sells from the earliest-expiring batch that still has stock', function () {
    $early = receiveBatch($this->item->uuid, 'EARLY', '2026-12-01', 5);
    $late = receiveBatch($this->item->uuid, 'LATE', '2027-12-01', 5);

    expect($this->items->sellableBatch($this->item->uuid)->uuid)->toBe($early->uuid);

    // Empty the earlier batch; FEFO must move on rather than keep pointing at it.
    $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $early->uuid, quantity: 5, deviceId: DEVICE);

    expect($this->items->sellableBatch($this->item->uuid)->uuid)->toBe($late->uuid);
});

it('ignores batch receipt order when picking by expiry', function () {
    // The batch received SECOND expires first. Picking by insertion order - the
    // easy mistake - would leave the shop sitting on stock until it expired.
    $late = receiveBatch($this->item->uuid, 'RECEIVED-FIRST', '2028-01-01', 5);
    $early = receiveBatch($this->item->uuid, 'RECEIVED-SECOND', '2026-06-01', 5);

    expect($this->items->sellableBatch($this->item->uuid)->uuid)->toBe($early->uuid);
});

it('returns nothing sellable for an item with no batches', function () {
    $bare = $this->items->create(itemCode: 'BARE', itemName: 'No batches', deviceId: DEVICE);

    expect($this->items->sellableBatch($bare->uuid))->toBeNull();
});

it('falls back to the earliest batch when every batch is empty', function () {
    /*
     * Faithful to the Python: when nothing has stock, the earliest batch is
     * still returned rather than null, so an oversell is attributed to a real
     * batch instead of dangling with batch_uuid = null.
     *
     * Negative stock is allowed on purpose - the shelf stops a sale, not the
     * database - so this path is reached in normal trading, not just in tests.
     */
    $batch = receiveBatch($this->item->uuid, 'ONLY', '2027-01-01', 5);
    $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $batch->uuid, quantity: 5, deviceId: DEVICE);

    expect($this->stock->batchQuantityFor($batch->uuid))->toBe(0)
        ->and($this->items->sellableBatch($this->item->uuid)->uuid)->toBe($batch->uuid);
});

it('picks the sellable batch in a bounded number of queries', function () {
    // The Python runs one SUM() per batch. An item with 40 received batches is
    // ordinary after a year of trading, and this is on the POS hot path.
    foreach (range(1, 40) as $n) {
        receiveBatch($this->item->uuid, "B-{$n}", '2027-'.str_pad((string) (($n % 12) + 1), 2, '0', STR_PAD_LEFT).'-01', 2);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->items->sellableBatch($this->item->uuid);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2);

    DB::disableQueryLog();
});

// ---------------------------------------------------------------------------
// editing
// ---------------------------------------------------------------------------

it('applies an edit and moves updated_at', function () {
    $before = $this->item->updated_at;

    // Advance the clock rather than trusting sub-second precision: the column is
    // a DATETIME, so an edit in the same second as the create would compare
    // equal and this test would pass or fail on timing.
    $this->travel(1)->second();

    $this->items->update($this->item, DEVICE, ['sales_price' => '25.00', 'reorder_level' => 15]);

    expect($this->item->sales_price)->toBe('25.00')
        ->and($this->item->reorder_level)->toBe(15)
        // A genuine edit to a hashed column must move updated_at - it is what
        // drives last-write-wins between two devices.
        ->and($this->item->updated_at->gt($before))->toBeTrue();
});

it('refuses to edit the item code', function () {
    // The code is the human key and may already be printed on a receipt or a
    // shelf label. Changing it would orphan both.
    expect(fn () => $this->items->update($this->item, DEVICE, ['item_code' => 'NEW-CODE']))
        ->toThrow(ItemException::class);
});

it('rejects an unknown field or a blank name on edit', function () {
    expect(fn () => $this->items->update($this->item, DEVICE, ['nope' => 1]))
        ->toThrow(ItemException::class);

    expect(fn () => $this->items->update($this->item, DEVICE, ['item_name' => '  ']))
        ->toThrow(ItemException::class);
});

it('rejects an edit setting a barcode another item already has', function () {
    $this->items->create(itemCode: 'OTHER', itemName: 'Other', deviceId: DEVICE, barcode: '777');

    expect(fn () => $this->items->update($this->item, DEVICE, ['barcode' => '777']))
        ->toThrow(ItemException::class);
});

it('lets an item keep its own barcode on edit', function () {
    // Re-submitting an unchanged edit form must not trip the uniqueness check
    // against the item itself.
    $item = $this->items->create(itemCode: 'SELF', itemName: 'Self', deviceId: DEVICE, barcode: '555');

    $this->items->update($item, DEVICE, ['barcode' => '555', 'item_name' => 'Renamed']);

    expect($item->item_name)->toBe('Renamed')
        ->and($item->barcode)->toBe('555');
});

it('stores a blanked barcode as null rather than an empty string', function () {
    // Empty strings collide with each other under a unique index; nulls do not.
    $item = $this->items->create(itemCode: 'CLR', itemName: 'Clear', deviceId: DEVICE, barcode: '666');

    $this->items->update($item, DEVICE, ['barcode' => '']);

    expect($item->fresh()->barcode)->toBeNull();
});

// ---------------------------------------------------------------------------
// stock alerts - a diagnostic list, never a blocking workflow
// ---------------------------------------------------------------------------

it('lists items at or below their reorder level', function () {
    $low = $this->items->create(itemCode: 'LOW', itemName: 'Low', deviceId: DEVICE);
    $this->items->update($low, DEVICE, ['reorder_level' => 10]);
    receiveBatch($low->uuid, 'B1', '2027-01-01', 8);

    $ok = $this->items->create(itemCode: 'OK', itemName: 'Ok', deviceId: DEVICE);
    $this->items->update($ok, DEVICE, ['reorder_level' => 5]);
    receiveBatch($ok->uuid, 'B2', '2027-01-01', 50);

    // 8 <= 10 is flagged; 50 > 5 is not. Boundary is inclusive: an item AT its
    // reorder level needs reordering.
    expect($this->items->lowStockItems()->pluck('qty', 'item.item_code')->all())
        ->toBe(['LOW' => 8]);
});

it('ignores items with no reorder level set', function () {
    // reorder_level 0 means "not tracked", not "reorder at zero" - otherwise
    // every item in the shop would sit permanently on the alert screen.
    receiveBatch($this->item->uuid, 'B-X', '2027-01-01', 0 + 1);

    expect($this->items->lowStockItems())->toBeEmpty();
});

it('separates expiring batches from already-expired ones', function () {
    $today = BusinessDate::for();

    receiveBatch($this->item->uuid, 'SOON', $today->addDays(10)->toDateString());
    receiveBatch($this->item->uuid, 'GONE', $today->subDays(3)->toDateString());
    receiveBatch($this->item->uuid, 'FAR', $today->addDays(200)->toDateString());

    expect($this->items->expiringBatches(30)->pluck('batch.batch_number')->all())->toBe(['SOON'])
        ->and($this->items->expiredBatches()->pluck('batch.batch_number')->all())->toBe(['GONE']);
});

it('orders expiring batches soonest first', function () {
    $today = BusinessDate::for();

    receiveBatch($this->item->uuid, 'DAY-20', $today->addDays(20)->toDateString());
    receiveBatch($this->item->uuid, 'DAY-2', $today->addDays(2)->toDateString());
    receiveBatch($this->item->uuid, 'DAY-11', $today->addDays(11)->toDateString());

    // Whoever is clearing shelves works the list top-down, so the order is the
    // whole value of the screen.
    expect($this->items->expiringBatches(30)->pluck('batch.batch_number')->all())
        ->toBe(['DAY-2', 'DAY-11', 'DAY-20']);
});

it('ignores empty batches in the expiry alerts', function () {
    $batch = receiveBatch($this->item->uuid, 'SOON', BusinessDate::for()->addDays(5)->toDateString());
    $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $batch->uuid, quantity: 5, deviceId: DEVICE);

    // An empty batch cannot be pulled off a shelf; warning about it is noise
    // that makes staff stop reading the alert screen.
    expect($this->items->expiringBatches(30))->toBeEmpty()
        ->and($this->items->expiredBatches())->toBeEmpty();
});

it('carries the item alongside each flagged batch', function () {
    receiveBatch($this->item->uuid, 'SOON', BusinessDate::for()->addDays(5)->toDateString());

    // The screen needs the item name; without it every row would be a lookup.
    $flagged = $this->items->expiringBatches(30)->first();

    expect($flagged['item']->item_name)->toBe('Panadol 500mg');
});

it('scans the expiry alerts in a bounded number of queries', function () {
    // The Python runs one SUM() per batch across the WHOLE catalogue to find
    // batches with stock. That is the slowest thing on the alerts screen.
    foreach (range(1, 30) as $n) {
        $item = $this->items->create(itemCode: "X-{$n}", itemName: "Item {$n}", deviceId: DEVICE);
        receiveBatch($item->uuid, "B-{$n}", BusinessDate::for()->addDays($n)->toDateString());
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->items->expiringBatches(30);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(3);

    DB::disableQueryLog();
});
