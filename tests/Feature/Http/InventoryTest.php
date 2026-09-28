<?php

/*
 * The inventory screens.
 *
 * The rules themselves are proved in ItemServiceTest and StockServiceTest. These
 * check the HTTP layer: that the right thing reaches the service, that a rule
 * violation comes back as a message beside a field rather than a 500, and that
 * no screen reads a quantity from the cache column.
 */

use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\StockService;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->staff = User::factory()->create(['username' => 'ahmed']);
    $this->stock = app(StockService::class);

    $this->item = Item::factory()->create([
        'item_code' => 'PANADOL-500',
        'item_name' => 'Panadol 500mg',
        'sales_price' => '20.00',
        'purchase_price' => '12.00',
        'reorder_level' => 10,
    ]);
});

// ---------------------------------------------------------------------------
// the list
// ---------------------------------------------------------------------------

it('lists items with stock derived from the ledger', function () {
    $batch = ItemBatch::factory()->for($this->item, 'item')->create();
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $batch->uuid, quantity: 42, deviceId: DEVICE);

    $this->actingAs($this->staff)->get(route('inventory.index'))
        ->assertOk()
        ->assertSee('Panadol 500mg')
        ->assertSee('42');
});

it('shows the ledger total, not a stale cache column', function () {
    /*
     * The failure this whole design exists to prevent. A screen reading
     * current_stock_qty would print 999; the ledger says 5.
     */
    $batch = ItemBatch::factory()->for($this->item, 'item')->create();
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $batch->uuid, quantity: 5, deviceId: DEVICE);

    DB::table('items')->where('uuid', $this->item->uuid)->update(['current_stock_qty' => 999]);

    $this->actingAs($this->staff)->get(route('inventory.index'))
        ->assertOk()
        ->assertDontSee('999');
});

it('totals the whole page of stock in one aggregate query', function () {
    // §5's second named trap. A SUM() per row makes a 50-item page issue 50
    // queries, which is why the Python client paginates so hard.
    $items = Item::factory()->count(30)->create();

    foreach ($items as $item) {
        $batch = ItemBatch::factory()->for($item, 'item')->create();
        $this->stock->receive(itemUuid: $item->uuid, batchUuid: $batch->uuid, quantity: 5, deviceId: DEVICE);
    }

    $this->actingAs($this->staff);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get(route('inventory.index'))->assertOk();

    // Generous: the page also loads the user, the session, settings and the
    // drawer. The point is that it does not scale with the row count.
    expect(count(DB::getQueryLog()))->toBeLessThan(20);

    DB::disableQueryLog();
});

it('searches by name, code and barcode', function () {
    Item::factory()->create(['item_code' => 'BRUFEN-400', 'item_name' => 'Brufen', 'barcode' => '8964999']);

    $this->actingAs($this->staff);

    $this->get(route('inventory.index', ['q' => 'brufen']))->assertOk()
        ->assertSee('Brufen')->assertDontSee('Panadol 500mg');

    $this->get(route('inventory.index', ['q' => '8964999']))->assertOk()->assertSee('Brufen');
    $this->get(route('inventory.index', ['q' => 'PANADOL-500']))->assertOk()->assertSee('Panadol 500mg');
});

it('hides inactive items unless asked for them', function () {
    Item::factory()->inactive()->create(['item_code' => 'OLD', 'item_name' => 'Discontinued']);

    $this->actingAs($this->staff);

    $this->get(route('inventory.index'))->assertOk()->assertDontSee('Discontinued');
    $this->get(route('inventory.index', ['show' => 'inactive']))->assertOk()->assertSee('Discontinued');
});

// ---------------------------------------------------------------------------
// the item screen
// ---------------------------------------------------------------------------

it('shows an item with its batches and its ledger', function () {
    $batch = ItemBatch::factory()->for($this->item, 'item')->create(['batch_number' => 'B-2211']);
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $batch->uuid, quantity: 50, deviceId: DEVICE);
    $this->stock->adjust(
        itemUuid: $this->item->uuid, qtyChange: -2, reason: 'two strips crushed',
        deviceId: DEVICE, batchUuid: $batch->uuid,
    );

    $this->actingAs($this->staff)->get(route('inventory.show', $this->item))
        ->assertOk()
        ->assertSee('B-2211')
        ->assertSee('48')                       // 50 - 2, derived
        // The ledger is on this screen because it is what answers "why does it
        // say that?" - the entire reason for keeping movements over a counter.
        ->assertSee('two strips crushed')
        ->assertSee('purchase')
        ->assertSee('adjustment');
});

it('marks which batch sells next', function () {
    $early = ItemBatch::factory()->for($this->item, 'item')->create([
        'batch_number' => 'EARLY', 'expiry_date' => '2026-12-01',
    ]);
    ItemBatch::factory()->for($this->item, 'item')->create([
        'batch_number' => 'LATE', 'expiry_date' => '2028-12-01',
    ]);
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $early->uuid, quantity: 5, deviceId: DEVICE);

    $this->actingAs($this->staff)->get(route('inventory.show', $this->item))
        ->assertOk()
        ->assertSee('Next to sell')
        ->assertSee('EARLY');
});

// ---------------------------------------------------------------------------
// adding and editing
// ---------------------------------------------------------------------------

it('adds an item without putting any stock on the shelf', function () {
    $this->actingAs($this->staff)->post(route('inventory.store'), [
        'item_code' => 'BRUFEN-400',
        'item_name' => 'Brufen 400mg',
        'purchase_price' => '8.00',
        'sales_price' => '15.00',
        'reorder_level' => 20,
    ])->assertRedirect();

    $created = Item::where('item_code', 'BRUFEN-400')->first();

    expect($created)->not->toBeNull()
        ->and($created->sales_price)->toBe('15.00')
        // Adding a catalogue entry is not receiving goods. Stock only exists
        // once a batch is received against it.
        ->and($this->stock->quantityFor($created->uuid))->toBe(0)
        ->and($created->batches()->count())->toBe(0);
});

it('returns a duplicate code to the form rather than failing', function () {
    $this->actingAs($this->staff)->post(route('inventory.store'), [
        'item_code' => 'PANADOL-500',
        'item_name' => 'Another Panadol',
        'purchase_price' => '1.00',
        'sales_price' => '2.00',
    ])->assertSessionHasErrors('item_code');
});

it('returns a duplicate barcode to the form', function () {
    Item::factory()->create(['item_code' => 'A-1', 'barcode' => '111']);

    $this->actingAs($this->staff)->post(route('inventory.store'), [
        'item_code' => 'A-2',
        'item_name' => 'Second',
        'barcode' => '111',
        'purchase_price' => '1.00',
        'sales_price' => '2.00',
    ])->assertSessionHasErrors('item_code');
});

it('edits an item', function () {
    $this->actingAs($this->staff)->put(route('inventory.update', $this->item), [
        'item_name' => 'Panadol Extra',
        'purchase_price' => '13.00',
        'sales_price' => '25.00',
        'reorder_level' => 15,
        'is_active' => '1',
    ])->assertRedirect(route('inventory.show', $this->item));

    expect($this->item->fresh()->item_name)->toBe('Panadol Extra')
        ->and($this->item->fresh()->sales_price)->toBe('25.00');
});

it('ignores an attempt to change the item code through the edit form', function () {
    // The code is the human key and may already be on a printed receipt. The
    // form disables the field; this proves a hand-crafted POST cannot get past it.
    $this->actingAs($this->staff)->put(route('inventory.update', $this->item), [
        'item_code' => 'HACKED',
        'item_name' => 'Panadol 500mg',
        'purchase_price' => '12.00',
        'sales_price' => '20.00',
    ])->assertRedirect();

    expect($this->item->fresh()->item_code)->toBe('PANADOL-500');
});

it('deactivates an item from the edit form', function () {
    $this->actingAs($this->staff)->put(route('inventory.update', $this->item), [
        'item_name' => 'Panadol 500mg',
        'purchase_price' => '12.00',
        'sales_price' => '20.00',
        // is_active omitted - an unticked checkbox sends nothing.
    ])->assertRedirect();

    expect($this->item->fresh()->is_active)->toBeFalse();
});

// ---------------------------------------------------------------------------
// receiving
// ---------------------------------------------------------------------------

it('receives stock into a new batch', function () {
    $this->actingAs($this->staff)->post(route('inventory.receive', $this->item), [
        'batch_number' => 'B-2211',
        'expiry_date' => '2027-06-01',
        'quantity' => 100,
        'purchase_price' => '12.50',
    ])->assertRedirect(route('inventory.show', $this->item));

    $batch = ItemBatch::where('batch_number', 'B-2211')->first();

    expect($batch)->not->toBeNull()
        ->and($batch->purchase_price)->toBe('12.50')
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(100);

    // Through the ledger, attributed to whoever received it.
    $ledger = StockTransaction::where('batch_uuid', $batch->uuid)->first();

    expect($ledger->transaction_type)->toBe('purchase')
        ->and($ledger->qty_change)->toBe(100)
        ->and($ledger->performed_by_user_uuid)->toBe($this->staff->uuid);
});

it('requires an expiry date to receive', function () {
    // FEFO orders by it, so a batch without one could never be ordered and
    // therefore never sold.
    $this->actingAs($this->staff)->post(route('inventory.receive', $this->item), [
        'batch_number' => 'B-1',
        'quantity' => 10,
        'purchase_price' => '1.00',
    ])->assertSessionHasErrors('expiry_date');

    expect(ItemBatch::count())->toBe(0);
});

it('refuses a zero or negative received quantity', function () {
    foreach ([0, -5] as $quantity) {
        $this->actingAs($this->staff)->post(route('inventory.receive', $this->item), [
            'batch_number' => 'B-1',
            'expiry_date' => '2027-01-01',
            'quantity' => $quantity,
            'purchase_price' => '1.00',
        ])->assertSessionHasErrors('quantity');
    }
});

it('returns a duplicate batch number to the form', function () {
    $this->actingAs($this->staff)->post(route('inventory.receive', $this->item), [
        'batch_number' => 'B-DUP', 'expiry_date' => '2027-01-01',
        'quantity' => 10, 'purchase_price' => '1.00',
    ]);

    // Two batches sharing a number cannot be told apart in a recall.
    $this->actingAs($this->staff)->post(route('inventory.receive', $this->item), [
        'batch_number' => 'B-DUP', 'expiry_date' => '2028-01-01',
        'quantity' => 10, 'purchase_price' => '1.00',
    ])->assertSessionHasErrors('batch_number');

    expect(ItemBatch::where('batch_number', 'B-DUP')->count())->toBe(1)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(10);
});

it('refuses a manufacture date after the expiry', function () {
    $this->actingAs($this->staff)->post(route('inventory.receive', $this->item), [
        'batch_number' => 'B-1',
        'expiry_date' => '2027-01-01',
        'mfg_date' => '2027-06-01',
        'quantity' => 10,
        'purchase_price' => '1.00',
    ])->assertSessionHasErrors('mfg_date');
});

// ---------------------------------------------------------------------------
// adjusting
// ---------------------------------------------------------------------------

it('records an adjustment as a new movement', function () {
    $batch = ItemBatch::factory()->for($this->item, 'item')->create();
    $received = $this->stock->receive(
        itemUuid: $this->item->uuid, batchUuid: $batch->uuid, quantity: 50, deviceId: DEVICE
    );

    $this->actingAs($this->staff)->post(route('inventory.adjust', $this->item), [
        'qty_change' => -3,
        'reason' => 'counted the shelf, three short',
        'batch_uuid' => $batch->uuid,
    ])->assertRedirect(route('inventory.show', $this->item));

    expect($this->stock->quantityFor($this->item->uuid))->toBe(47)
        // The original movement is untouched: the ledger is append-only.
        ->and($received->fresh()->qty_change)->toBe(50)
        ->and(StockTransaction::where('item_uuid', $this->item->uuid)->count())->toBe(2);
});

it('requires a reason for an adjustment', function () {
    // An adjustment is the only movement with no delivery note behind it, and
    // the place shrinkage would hide.
    $this->actingAs($this->staff)->post(route('inventory.adjust', $this->item), [
        'qty_change' => -3,
    ])->assertSessionHasErrors('reason');

    $this->actingAs($this->staff)->post(route('inventory.adjust', $this->item), [
        'qty_change' => -3,
        'reason' => 'x',        // too short to mean anything
    ])->assertSessionHasErrors('reason');

    expect(StockTransaction::count())->toBe(0);
});

it('refuses a zero adjustment', function () {
    // A zero movement records nothing.
    $this->actingAs($this->staff)->post(route('inventory.adjust', $this->item), [
        'qty_change' => 0,
        'reason' => 'no change at all',
    ])->assertSessionHasErrors('qty_change');
});

it('allows a positive adjustment', function () {
    // Stock found behind a shelf is as real as stock lost off one.
    $this->actingAs($this->staff)->post(route('inventory.adjust', $this->item), [
        'qty_change' => 7,
        'reason' => 'found a box behind the counter',
    ])->assertRedirect();

    expect($this->stock->quantityFor($this->item->uuid))->toBe(7);
});

// ---------------------------------------------------------------------------
// alerts
// ---------------------------------------------------------------------------

it('shows low stock, expiring, expired and negative stock on one screen', function () {
    $today = BusinessDate::for();

    // Low: 8 against a reorder level of 10.
    $lowBatch = ItemBatch::factory()->for($this->item, 'item')->create([
        'batch_number' => 'LOW-B', 'expiry_date' => $today->addYear()->toDateString(),
    ]);
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $lowBatch->uuid, quantity: 8, deviceId: DEVICE);

    // Expiring soon.
    $soon = Item::factory()->create(['item_code' => 'SOON-ITEM', 'item_name' => 'Expiring Soon']);
    $soonBatch = ItemBatch::factory()->for($soon, 'item')->create([
        'batch_number' => 'SOON-B', 'expiry_date' => $today->addDays(10)->toDateString(),
    ]);
    $this->stock->receive(itemUuid: $soon->uuid, batchUuid: $soonBatch->uuid, quantity: 5, deviceId: DEVICE);

    // Already expired, still holding stock.
    $gone = Item::factory()->create(['item_code' => 'GONE-ITEM', 'item_name' => 'Already Expired']);
    $goneBatch = ItemBatch::factory()->for($gone, 'item')->create([
        'batch_number' => 'GONE-B', 'expiry_date' => $today->subDays(5)->toDateString(),
    ]);
    $this->stock->receive(itemUuid: $gone->uuid, batchUuid: $goneBatch->uuid, quantity: 3, deviceId: DEVICE);

    // Negative: oversold, which is permitted - the shelf stops a sale, not the DB.
    $over = Item::factory()->create(['item_code' => 'NEG-ITEM', 'item_name' => 'Oversold Item']);
    $overBatch = ItemBatch::factory()->for($over, 'item')->create();
    $this->stock->receive(itemUuid: $over->uuid, batchUuid: $overBatch->uuid, quantity: 2, deviceId: DEVICE);
    $this->stock->sell(itemUuid: $over->uuid, batchUuid: $overBatch->uuid, quantity: 5, deviceId: DEVICE);

    $this->actingAs($this->staff)->get(route('inventory.alerts'))
        ->assertOk()
        ->assertSee('Panadol 500mg')        // low
        ->assertSee('Expiring Soon')
        ->assertSee('Already Expired')
        ->assertSee('Oversold Item')
        ->assertSee('-3');                  // the negative figure itself
});

it('leaves an empty batch out of the expiry alerts', function () {
    // An empty batch cannot be pulled off a shelf, and warning about it is the
    // noise that makes staff stop reading the screen.
    $batch = ItemBatch::factory()->for($this->item, 'item')->create([
        'batch_number' => 'EMPTY-B',
        'expiry_date' => BusinessDate::for()->addDays(5)->toDateString(),
    ]);
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $batch->uuid, quantity: 5, deviceId: DEVICE);
    $this->stock->sell(itemUuid: $this->item->uuid, batchUuid: $batch->uuid, quantity: 5, deviceId: DEVICE);

    $this->actingAs($this->staff)->get(route('inventory.alerts'))
        ->assertOk()
        ->assertDontSee('EMPTY-B');
});

it('says so plainly when there is nothing to deal with', function () {
    // The fixture item tracks a reorder level of 10 and holds nothing, so it is
    // correctly low. Untrack it to get a genuinely clean slate.
    $this->item->forceFill(['reorder_level' => 0])->save();

    $this->actingAs($this->staff)->get(route('inventory.alerts'))
        ->assertOk()
        ->assertSee('Nothing needs reordering')
        ->assertSee('Nothing expiring soon');
});

it('flags an item that is tracked but holds nothing', function () {
    // 0 against a reorder level of 10 is exactly what the alert is for: it is
    // out of stock AND being tracked, which is the case that loses a sale.
    $this->actingAs($this->staff)->get(route('inventory.alerts'))
        ->assertOk()
        ->assertSee('At or below reorder level')
        ->assertSee('Panadol 500mg');
});
