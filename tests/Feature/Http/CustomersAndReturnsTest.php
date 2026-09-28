<?php

/*
 * The customer and returns screens.
 *
 * The rules are proved in CustomerServiceTest and ReturnServiceTest. These check
 * the HTTP layer: that a refund reaches the drawer, that the cap holds against a
 * hand-crafted POST, and that a rule violation comes back as a message beside a
 * field rather than a 500.
 */

use App\Models\Customer;
use App\Models\FamilyMember;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\CartLine;
use App\Services\SaleService;
use App\Services\SessionService;
use App\Services\StockService;

beforeEach(function () {
    $this->staff = User::factory()->create(['username' => 'ahmed']);
    $this->stock = app(StockService::class);

    $this->customer = Customer::create([
        'phone_number' => '0300-1234567',
        'primary_contact_name' => 'Fatima Bibi',
        'origin_device_id' => DEVICE,
    ]);

    $this->item = Item::factory()->create(['item_code' => 'PANADOL-500', 'item_name' => 'Panadol 500mg']);
    $this->batch = ItemBatch::factory()->for($this->item, 'item')->create(['batch_number' => 'B-2211']);
    $this->stock->receive(itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 100, deviceId: DEVICE);

    $this->sale = app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 5, '100.00', $this->batch->uuid)],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
        customerUuid: $this->customer->uuid,
    );

    $this->saleLine = $this->sale->lines()->first();
});

// ---------------------------------------------------------------------------
// customers
// ---------------------------------------------------------------------------

it('lists customers and keeps the walk-in sentinel out', function () {
    Customer::create([
        'phone_number' => Customer::WALK_IN_PHONE,
        'primary_contact_name' => 'Walk-in',
        'origin_device_id' => DEVICE,
    ]);

    $this->actingAs($this->staff)->get(route('customers.index'))
        ->assertOk()
        ->assertSee('0300-1234567')
        // A system row, not a patient.
        ->assertDontSee(Customer::WALK_IN_PHONE);
});

it('finds a customer by a partial phone number', function () {
    // Staff are read the last four digits across a counter far more often than
    // a whole number.
    $this->actingAs($this->staff)->get(route('customers.index', ['q' => '4567']))
        ->assertOk()
        ->assertSee('Fatima Bibi');
});

it('adds a customer', function () {
    $this->actingAs($this->staff)->post(route('customers.store'), [
        'phone_number' => '0321-9998887',
        'primary_contact_name' => 'Imran Ali',
        'city' => 'Lahore',
    ])->assertRedirect();

    expect(Customer::where('phone_number', '0321-9998887')->exists())->toBeTrue();
});

it('returns a duplicate phone number to the form', function () {
    $this->actingAs($this->staff)->post(route('customers.store'), [
        'phone_number' => '0300-1234567',
        'primary_contact_name' => 'Someone Else',
    ])->assertSessionHasErrors('phone_number');

    expect(Customer::count())->toBe(1);
});

it('refuses to change a phone number through the edit form', function () {
    // It is the identity. A hand-crafted POST must not move a household's whole
    // history onto another number.
    $this->actingAs($this->staff)->put(route('customers.update', $this->customer), [
        'phone_number' => '0399-0000000',
        'primary_contact_name' => 'Fatima Bibi',
    ])->assertRedirect();

    expect($this->customer->fresh()->phone_number)->toBe('0300-1234567');
});

it('shows a customer with their history', function () {
    $this->actingAs($this->staff)->get(route('customers.show', $this->customer))
        ->assertOk()
        ->assertSee('Fatima Bibi')
        ->assertSee($this->sale->invoice_number)
        ->assertSee('Panadol 500mg');
});

it('adds a family member', function () {
    $this->actingAs($this->staff)->post(route('customers.members.store', $this->customer), [
        'member_name' => 'Zainab',
        'relationship_type' => 'daughter',
        'notes' => 'penicillin allergy',
    ])->assertRedirect();

    $member = FamilyMember::where('member_name', 'Zainab')->first();

    expect($member)->not->toBeNull()
        ->and($member->relationship_type)->toBe('daughter');
});

it('refuses a relationship that is not one of the listed ones', function () {
    $this->actingAs($this->staff)->post(route('customers.members.store', $this->customer), [
        'member_name' => 'Zainab',
        'relationship_type' => 'cousin-twice-removed',
    ])->assertSessionHasErrors('relationship_type');
});

it('refuses a date of birth in the future', function () {
    // A typo, and paediatric dosing is one of the things this field is for.
    $this->actingAs($this->staff)->post(route('customers.members.store', $this->customer), [
        'member_name' => 'Zainab',
        'dob' => now()->addYear()->toDateString(),
    ])->assertSessionHasErrors('dob');
});

it('shows what one family member has been taking', function () {
    $member = FamilyMember::create([
        'customer_uuid' => $this->customer->uuid,
        'member_name' => 'Amina',
        'relationship_type' => 'mother',
        'notes' => 'warfarin - check interactions',
        'origin_device_id' => DEVICE,
    ]);

    app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 1, '100.00', $this->batch->uuid)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        customerUuid: $this->customer->uuid, familyMemberUuid: $member->uuid,
    );

    $this->actingAs($this->staff)->get(route('customers.members.show', $member))
        ->assertOk()
        ->assertSee('Amina')
        // The allergy note is the one thing here that could stop a mistake.
        ->assertSee('warfarin - check interactions')
        ->assertSee('Panadol 500mg');
});

// ---------------------------------------------------------------------------
// finding the sale to return against
// ---------------------------------------------------------------------------

it('finds a sale by invoice number', function () {
    $this->actingAs($this->staff)
        ->get(route('returns.find', ['invoice' => $this->sale->invoice_number]))
        ->assertOk()
        ->assertSee('Found it')
        ->assertSee($this->sale->invoice_number);
});

it('finds sales by phone number', function () {
    // The usual path: receipts get lost, phone numbers do not.
    $this->actingAs($this->staff)
        ->get(route('returns.find', ['phone' => '0300-1234567']))
        ->assertOk()
        ->assertSee($this->sale->invoice_number);
});

it('says so plainly when no sale matches', function () {
    $this->actingAs($this->staff)
        ->get(route('returns.find', ['invoice' => 'WEB-19990101-0001']))
        ->assertOk()
        ->assertSee('No sale found');
});

// ---------------------------------------------------------------------------
// the return form shows what REMAINS
// ---------------------------------------------------------------------------

it('shows the returnable quantity rather than the original', function () {
    /*
     * Showing the original quantity is how the same line gets refunded twice -
     * the bug this port fixed in the Python. Two of five have already gone back,
     * so the form must offer three.
     */
    $this->actingAs($this->staff)->post(route('returns.store', $this->sale), [
        'lines' => [['sale_item_uuid' => $this->saleLine->uuid, 'quantity' => 2]],
        'refund_method' => 'cash',
    ]);

    $this->actingAs($this->staff)->get(route('returns.create', $this->sale))
        ->assertOk()
        ->assertSee('max="3"', escape: false);
});

// ---------------------------------------------------------------------------
// processing the return
// ---------------------------------------------------------------------------

it('records a refund and puts the stock back', function () {
    $this->actingAs($this->staff)->post(route('returns.store', $this->sale), [
        'lines' => [['sale_item_uuid' => $this->saleLine->uuid, 'quantity' => 3]],
        'refund_method' => 'cash',
    ])->assertRedirect();

    $return = SaleReturn::first();

    expect($return->total_amount)->toBe('300.00')
        // 100 received, 5 sold, 3 back.
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(98)
        // Into the batch it came from, so its expiry stays correct.
        ->and($this->stock->batchQuantityFor($this->batch->uuid))->toBe(98)
        ->and($this->sale->fresh()->is_returned)->toBeTrue();
});

it('attaches a cash refund to the open drawer', function () {
    /*
     * Without this the refund would belong to no session, tonight's expected
     * cash would not account for money handed back, and the drawer would read
     * over by the refund with nothing to explain it.
     */
    $drawer = app(SessionService::class)->open(
        userUuid: $this->staff->uuid, openingFloat: '1000.00', deviceId: DEVICE,
    );

    $this->actingAs($this->staff)->post(route('returns.store', $this->sale), [
        'lines' => [['sale_item_uuid' => $this->saleLine->uuid, 'quantity' => 2]],
        'refund_method' => 'cash',
    ])->assertRedirect();

    expect(SaleReturn::first()->session_uuid)->toBe($drawer->uuid)
        ->and(app(SessionService::class)->cashRefundsTotal($drawer->uuid))->toBe('200.00')
        // 1000 float, no cash sales in this session, less the 200 refunded.
        ->and(app(SessionService::class)->expectedCash($drawer))->toBe('800.00');
});

it('ignores lines left at zero', function () {
    // Staff leave untouched rows alone rather than deleting them from the form.
    $second = Item::factory()->create(['item_code' => 'BRUFEN']);
    $secondBatch = ItemBatch::factory()->for($second, 'item')->create();
    $this->stock->receive(itemUuid: $second->uuid, batchUuid: $secondBatch->uuid, quantity: 50, deviceId: DEVICE);

    $sale = app(SaleService::class)->create(
        lines: [
            new CartLine($this->item->uuid, 2, '100.00', $this->batch->uuid),
            new CartLine($second->uuid, 3, '10.00', $secondBatch->uuid),
        ],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    $lines = $sale->lines;

    $this->actingAs($this->staff)->post(route('returns.store', $sale), [
        'lines' => [
            ['sale_item_uuid' => $lines[0]->uuid, 'quantity' => 1],
            ['sale_item_uuid' => $lines[1]->uuid, 'quantity' => 0],
        ],
        'refund_method' => 'cash',
    ])->assertRedirect();

    $return = SaleReturn::first();

    expect($return->lines()->count())->toBe(1)
        ->and($return->total_amount)->toBe('100.00');
});

it('refuses a form with every line at zero', function () {
    $this->actingAs($this->staff)->post(route('returns.store', $this->sale), [
        'lines' => [['sale_item_uuid' => $this->saleLine->uuid, 'quantity' => 0]],
        'refund_method' => 'cash',
    ])->assertSessionHasErrors('lines');

    expect(SaleReturn::count())->toBe(0);
});

it('holds the cap against a hand-crafted post', function () {
    // The form caps the input with max=, which a crafted POST ignores. The
    // service is what actually enforces it, and the message comes back to the form.
    $this->actingAs($this->staff)->post(route('returns.store', $this->sale), [
        'lines' => [['sale_item_uuid' => $this->saleLine->uuid, 'quantity' => 6]],
        'refund_method' => 'cash',
    ])->assertSessionHasErrors('lines');

    expect(SaleReturn::count())->toBe(0)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(95);
});

it('counts an earlier return against a later one', function () {
    $this->actingAs($this->staff)->post(route('returns.store', $this->sale), [
        'lines' => [['sale_item_uuid' => $this->saleLine->uuid, 'quantity' => 3]],
        'refund_method' => 'cash',
    ])->assertRedirect();

    // Two remain, so three more must be refused.
    $this->actingAs($this->staff)->post(route('returns.store', $this->sale), [
        'lines' => [['sale_item_uuid' => $this->saleLine->uuid, 'quantity' => 3]],
        'refund_method' => 'cash',
    ])->assertSessionHasErrors('lines');

    expect(SaleReturn::count())->toBe(1);
});

it('refuses a refund method the shop does not take', function () {
    $this->actingAs($this->staff)->post(route('returns.store', $this->sale), [
        'lines' => [['sale_item_uuid' => $this->saleLine->uuid, 'quantity' => 1]],
        'refund_method' => 'cheque',        // suppliers, not customers
    ])->assertSessionHasErrors('refund_method');
});

it('leaves the original sale amounts untouched', function () {
    $before = $this->sale->net_amount;

    $this->actingAs($this->staff)->post(route('returns.store', $this->sale), [
        'lines' => [['sale_item_uuid' => $this->saleLine->uuid, 'quantity' => 5]],
        'refund_method' => 'cash',
    ])->assertRedirect();

    // The history of what was sold stays true even after all of it came back.
    expect($this->sale->fresh()->net_amount)->toBe($before)
        ->and($this->sale->fresh()->lines()->first()->quantity)->toBe(5);
});

// ---------------------------------------------------------------------------
// the record afterwards
// ---------------------------------------------------------------------------

it('shows and prints the refund slip', function () {
    $this->actingAs($this->staff)->post(route('returns.store', $this->sale), [
        'lines' => [['sale_item_uuid' => $this->saleLine->uuid, 'quantity' => 2]],
        'refund_method' => 'cash',
    ]);

    $return = SaleReturn::first();

    $this->actingAs($this->staff)->get(route('returns.show', $return))
        ->assertOk()
        ->assertSee($return->return_number)
        ->assertSee('B-2211');

    $this->actingAs($this->staff)->get(route('returns.receipt', $return))
        ->assertOk()
        // Headed REFUND, so the slip cannot be presented as proof of purchase
        // for stock that has already gone back.
        ->assertSee('REFUND')
        ->assertSee($return->return_number);
});

it('lists past returns', function () {
    $this->actingAs($this->staff)->post(route('returns.store', $this->sale), [
        'lines' => [['sale_item_uuid' => $this->saleLine->uuid, 'quantity' => 1]],
        'refund_method' => 'cash',
    ]);

    $this->actingAs($this->staff)->get(route('returns.index'))
        ->assertOk()
        ->assertSee(SaleReturn::first()->return_number)
        ->assertSee('Fatima Bibi');
});
