<?php

/*
 * The till.
 *
 * No Python counterpart - the Flask POS is full-page POSTs and its tests live in
 * tests/test_web_pos.py - so these are written against the Livewire component.
 *
 * The properties that matter most here are the ones that protect the customer
 * standing at the counter: the cart survives a wrong PIN, stock never blocks a
 * sale, and the money on screen is exactly the money recorded.
 */

use App\Livewire\PointOfSale;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\PinService;
use App\Services\SessionService;
use App\Services\StockService;
use Livewire\Livewire;

beforeEach(function () {
    $this->stock = app(StockService::class);

    $this->staff = User::factory()->create(['username' => 'ahmed', 'full_name' => 'Ahmed Ali']);
    app(PinService::class)->setPin($this->staff, '1111');

    $this->manager = User::factory()->manager()->create(['username' => 'bilal']);
    app(PinService::class)->setPin($this->manager, '2222');

    $this->item = Item::factory()->create([
        'item_code' => 'PANADOL-500',
        'item_name' => 'Panadol 500mg',
        'sales_price' => '100.00',
        'barcode' => '8964000111222',
    ]);

    $this->batch = ItemBatch::factory()->for($this->item, 'item')->create(['batch_number' => 'B-1']);
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 50, deviceId: DEVICE);

    $this->actingAs($this->staff);
});

// ---------------------------------------------------------------------------
// reaching the screen
// ---------------------------------------------------------------------------

it('renders the till', function () {
    $this->get(route('pos.index'))->assertOk()->assertSee('Search or scan');
});

it('warns when no drawer is open without blocking the sale', function () {
    // Refusing would stop the shop trading over bookkeeping.
    Livewire::test(PointOfSale::class)->assertSee('No drawer is open');
});

// ---------------------------------------------------------------------------
// search and scan
// ---------------------------------------------------------------------------

it('searches after two characters', function () {
    Livewire::test(PointOfSale::class)
        ->set('search', 'P')
        ->assertDontSee('Panadol 500mg')
        ->set('search', 'Pan')
        ->assertSee('Panadol 500mg');
});

it('adds an item on an exact barcode match', function () {
    // A scanner types the whole code and sends Enter in one burst, so submit
    // means "add this now" - the staff member is looking at the customer.
    Livewire::test(PointOfSale::class)
        ->set('search', '8964000111222')
        ->call('submitSearch')
        ->assertSet('search', '')
        ->assertCount('cart', 1);
});

it('leaves a non-barcode search in place', function () {
    Livewire::test(PointOfSale::class)
        ->set('search', 'Panadol')
        ->call('submitSearch')
        ->assertSet('search', 'Panadol')
        ->assertCount('cart', 0);
});

it('shows derived stock beside each result', function () {
    Livewire::test(PointOfSale::class)
        ->set('search', 'Panadol')
        ->assertSee('50 in stock');
});

// ---------------------------------------------------------------------------
// the cart
// ---------------------------------------------------------------------------

it('adds an item and picks its batch by FEFO', function () {
    // The earliest-expiring batch, chosen here rather than asked for: staff
    // should not be picking lots at a counter.
    $earlier = ItemBatch::factory()->for($this->item, 'item')
        ->create(['batch_number' => 'B-EARLY', 'expiry_date' => '2026-12-01']);
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $earlier->uuid, quantity: 10, deviceId: DEVICE);

    $component = Livewire::test(PointOfSale::class)->call('addItem', $this->item->uuid);

    expect(collect($component->get('cart'))->first()['batch_uuid'])->toBe($earlier->uuid);
});

it('increments an identical line rather than repeating it', function () {
    // What someone scanning three boxes of the same thing expects to see.
    $component = Livewire::test(PointOfSale::class)
        ->call('addItem', $this->item->uuid)
        ->call('addItem', $this->item->uuid)
        ->call('addItem', $this->item->uuid)
        ->assertCount('cart', 1);

    expect(collect($component->get('cart'))->first()['quantity'])->toBe(3);
});

it('increments and decrements a line', function () {
    $component = Livewire::test(PointOfSale::class)->call('addItem', $this->item->uuid);
    $lineId = array_key_first($component->get('cart'));

    $component->call('incrementLine', $lineId)->call('incrementLine', $lineId);
    expect($component->get('cart')[$lineId]['quantity'])->toBe(3);

    $component->call('decrementLine', $lineId);
    expect($component->get('cart')[$lineId]['quantity'])->toBe(2);
});

it('removes a line decremented to zero', function () {
    // Rather than leaving a zero-quantity row the save would then reject.
    $component = Livewire::test(PointOfSale::class)->call('addItem', $this->item->uuid);
    $lineId = array_key_first($component->get('cart'));

    $component->call('decrementLine', $lineId)->assertCount('cart', 0);
});

it('removes a line and clears the cart', function () {
    $component = Livewire::test(PointOfSale::class)->call('addItem', $this->item->uuid);
    $lineId = array_key_first($component->get('cart'));

    $component->call('removeLine', $lineId)->assertCount('cart', 0);

    $component->call('addItem', $this->item->uuid)->call('clearCart')->assertCount('cart', 0);
});

it('ignores an inactive item', function () {
    $retired = Item::factory()->inactive()->create(['item_code' => 'OLD']);

    Livewire::test(PointOfSale::class)->call('addItem', $retired->uuid)->assertCount('cart', 0);
});

// ---------------------------------------------------------------------------
// the money on screen
// ---------------------------------------------------------------------------

it('totals the cart exactly', function () {
    $awkward = Item::factory()->create(['item_code' => 'ODD', 'sales_price' => '19.99']);
    $batch = ItemBatch::factory()->for($awkward, 'item')->create();
    $this->stock->receive(itemUuid: $awkward->uuid, batchUuid: $batch->uuid, quantity: 100, deviceId: DEVICE);

    $component = Livewire::test(PointOfSale::class)->call('addItem', $awkward->uuid);
    $lineId = array_key_first($component->get('cart'));

    // 3 x 19.99 = 59.97, not 59.96999999.
    $component->set("cart.{$lineId}.quantity", 3);

    expect($component->get('subtotal'))->toBe('59.97')
        ->and($component->get('net'))->toBe('59.97');
});

it('resolves a percentage discount to rupees on screen', function () {
    // Shown resolved, so whoever is at the till sees the figure the customer
    // will see before committing to it.
    $component = Livewire::test(PointOfSale::class)
        ->call('addItem', $this->item->uuid)
        ->set('discountType', 'percentage')
        ->set('discountAmount', '10');

    expect($component->get('discount'))->toBe('10.00')
        ->and($component->get('net'))->toBe('90.00');
});

it('flags a discount larger than the total', function () {
    $component = Livewire::test(PointOfSale::class)
        ->call('addItem', $this->item->uuid)
        ->set('discountAmount', '150');

    expect($component->get('discountTooLarge'))->toBeTrue();

    $component->set('pin', '1111')->call('save')->assertHasErrors('discountAmount');

    expect(Sale::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// stock is advisory
// ---------------------------------------------------------------------------

it('flags a line that exceeds stock but still sells it', function () {
    /*
     * The shelf stops a sale, not the database. If stock says 2 and the shelf
     * has 5, refusing loses a real customer over a data error - so this is a
     * warning next to the line, and the sale goes through.
     */
    $component = Livewire::test(PointOfSale::class)->call('addItem', $this->item->uuid);
    $lineId = array_key_first($component->get('cart'));

    $component->set("cart.{$lineId}.quantity", 80)   // only 50 in stock
        ->assertSee('only 50 in stock');

    $component->set('pin', '1111')->call('save')->assertHasNoErrors();

    expect(Sale::count())->toBe(1)
        // And the ledger records the oversell rather than hiding it.
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(-30);
});

// ---------------------------------------------------------------------------
// saving, and the PIN
// ---------------------------------------------------------------------------

it('saves a sale attributed to the PIN owner', function () {
    $drawer = app(SessionService::class)->open(
        userUuid: $this->manager->uuid, openingFloat: '500.00', deviceId: DEVICE,
    );

    Livewire::test(PointOfSale::class)
        ->call('addItem', $this->item->uuid)
        ->set('pin', '1111')
        ->call('save')
        ->assertHasNoErrors();

    $sale = Sale::first();

    // Attributed to whoever entered the PIN, not to whoever is logged in: the
    // till is shared and often left signed in.
    expect($sale->staff_member_uuid)->toBe($this->staff->uuid)
        ->and($sale->session_uuid)->toBe($drawer->uuid)
        ->and($sale->net_amount)->toBe('100.00')
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(49);
});

it('attributes the sale to the PIN owner even when someone else is logged in', function () {
    // The manager is signed in; Ahmed rings the sale with his own PIN.
    $this->actingAs($this->manager);

    Livewire::test(PointOfSale::class)
        ->call('addItem', $this->item->uuid)
        ->set('pin', '1111')
        ->call('save');

    expect(Sale::first()->staff_member_uuid)->toBe($this->staff->uuid);
});

it('keeps the cart when the PIN is wrong', function () {
    /*
     * The single most important behaviour on this screen. Clearing the cart
     * would punish the customer for a staff member's mistyped digit, and a
     * colleague can finish the sale with their own PIN.
     */
    $component = Livewire::test(PointOfSale::class)
        ->call('addItem', $this->item->uuid)
        ->call('addItem', $this->item->uuid)
        ->set('pin', '9999')
        ->call('save');

    $component->assertHasErrors('pin')
        ->assertCount('cart', 1)          // still there
        ->assertSet('pin', '');           // but the pad is cleared

    expect(Sale::count())->toBe(0);
});

it('lets a colleague complete the cart after a wrong PIN', function () {
    $component = Livewire::test(PointOfSale::class)
        ->call('addItem', $this->item->uuid)
        ->set('pin', '9999')
        ->call('save')
        ->set('pin', '2222')              // the manager's PIN
        ->call('save')
        ->assertHasNoErrors();

    expect(Sale::first()->staff_member_uuid)->toBe($this->manager->uuid);
});

it('refuses to save an empty cart', function () {
    Livewire::test(PointOfSale::class)
        ->set('pin', '1111')
        ->call('save')
        ->assertHasErrors('cart');
});

it('refuses a PIN belonging to nobody', function () {
    Livewire::test(PointOfSale::class)
        ->call('addItem', $this->item->uuid)
        ->set('pin', '7777')
        ->call('save')
        ->assertHasErrors('pin');
});

it('clears the cart after a successful save', function () {
    Livewire::test(PointOfSale::class)
        ->call('addItem', $this->item->uuid)
        ->set('pin', '1111')
        ->call('save')
        ->assertCount('cart', 0)
        ->assertSee('saved');
});

// ---------------------------------------------------------------------------
// discount authorisation
// ---------------------------------------------------------------------------

it('demands a manager PIN for a discount above the threshold', function () {
    Setting::create([
        'discount_pin_threshold' => '100.00',
        'origin_device_id' => DEVICE,
    ]);

    $component = Livewire::test(PointOfSale::class)
        ->call('addItem', $this->item->uuid);

    $lineId = array_key_first($component->get('cart'));
    $component->set("cart.{$lineId}.quantity", 10)      // 1000.00
        ->set('discountAmount', '150');                 // above the 100 threshold

    expect($component->get('needsAuthorisation'))->toBeTrue();

    // Staff PIN alone is not enough.
    $component->set('pin', '1111')->call('save')->assertHasErrors('authorisingPin');
    expect(Sale::count())->toBe(0);
});

it('refuses a staff PIN as the authorising PIN', function () {
    Setting::create(['discount_pin_threshold' => '100.00', 'origin_device_id' => DEVICE]);

    $component = Livewire::test(PointOfSale::class)->call('addItem', $this->item->uuid);
    $lineId = array_key_first($component->get('cart'));

    $component->set("cart.{$lineId}.quantity", 10)
        ->set('discountAmount', '150')
        ->set('pin', '1111')
        ->set('authorisingPin', '1111')     // Ahmed cannot authorise his own
        ->call('save')
        ->assertHasErrors('authorisingPin');

    expect(Sale::count())->toBe(0);
});

it('accepts a manager PIN for a large discount and records the authorisation', function () {
    Setting::create(['discount_pin_threshold' => '100.00', 'origin_device_id' => DEVICE]);

    $component = Livewire::test(PointOfSale::class)->call('addItem', $this->item->uuid);
    $lineId = array_key_first($component->get('cart'));

    $component->set("cart.{$lineId}.quantity", 10)
        ->set('discountAmount', '150')
        ->set('discountReason', 'long-standing customer')
        ->set('pin', '1111')
        ->set('authorisingPin', '2222')
        ->call('save')
        ->assertHasNoErrors();

    $sale = Sale::first();

    expect($sale->discount_amount)->toBe('150.00')
        ->and($sale->net_amount)->toBe('850.00')
        // Recorded on the sale, so the discount is defensible later.
        ->and($sale->discount_pin_verified)->toBeTrue()
        ->and($sale->discount_reason)->toBe('long-standing customer')
        // Still attributed to whoever served, not to whoever authorised.
        ->and($sale->staff_member_uuid)->toBe($this->staff->uuid);
});

it('needs no authorisation for a small discount', function () {
    Setting::create(['discount_pin_threshold' => '100.00', 'origin_device_id' => DEVICE]);

    // A threshold set too low teaches everyone to fetch a manager for every
    // sale, which trains them to treat the check as noise.
    Livewire::test(PointOfSale::class)
        ->call('addItem', $this->item->uuid)
        ->set('discountAmount', '20')
        ->set('pin', '1111')
        ->call('save')
        ->assertHasNoErrors();

    expect(Sale::first()->discount_amount)->toBe('20.00');
});

// ---------------------------------------------------------------------------
// the customer
// ---------------------------------------------------------------------------

it('attaches a customer found by phone', function () {
    $customer = Customer::create([
        'phone_number' => '0300-1234567',
        'primary_contact_name' => 'Fatima',
        'origin_device_id' => DEVICE,
    ]);

    Livewire::test(PointOfSale::class)
        ->set('customerPhone', '0300-1234567')
        ->call('lookUpCustomer')
        ->assertSet('customerUuid', $customer->uuid)
        ->call('addItem', $this->item->uuid)
        ->set('pin', '1111')
        ->call('save');

    expect(Sale::first()->customer_uuid)->toBe($customer->uuid);
});

it('says so when the number is unknown, without blocking the sale', function () {
    // Most sales are to nobody in particular, and a customer record is created
    // deliberately elsewhere - not as a side-effect of a typo at the till.
    Livewire::test(PointOfSale::class)
        ->set('customerPhone', '0300-0000000')
        ->call('lookUpCustomer')
        ->assertHasErrors('customerPhone')
        ->call('addItem', $this->item->uuid)
        ->set('pin', '1111')
        ->call('save')
        ->assertHasNoErrors('cart');

    expect(Sale::first()->customer_uuid)->toBeNull();
});

// ---------------------------------------------------------------------------
// authorisation to be here at all
// ---------------------------------------------------------------------------

it('keeps a deactivated account off the till', function () {
    $this->staff->forceFill(['is_active' => false])->save();

    // Gate::before refuses everything to a deactivated account, so a session
    // open when the owner deactivates someone stops working immediately.
    $this->get(route('pos.index'))->assertForbidden();
});
