<?php

/*
 * Ported from tests/test_customer_service.py.
 *
 * A customer IS a phone number: the only thing reliably known at a counter. The
 * tests protect that identity, and the family-member split that makes "what has
 * my mother been taking" answerable.
 */

use App\Exceptions\CustomerException;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\User;
use App\Services\CartLine;
use App\Services\CustomerService;
use App\Services\ReturnLine;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;

beforeEach(function () {
    $this->customers = app(CustomerService::class);

    $this->customer = $this->customers->create(
        phone: '0300-1234567',
        deviceId: DEVICE,
        name: 'Fatima Bibi',
        city: 'Rawalpindi',
    );
});

// ---------------------------------------------------------------------------
// the phone number is the identity
// ---------------------------------------------------------------------------

it('creates a customer keyed on the phone number', function () {
    expect($this->customer->phone_number)->toBe('0300-1234567')
        ->and($this->customer->primary_contact_name)->toBe('Fatima Bibi')
        ->and($this->customer->is_active)->toBeTrue();
});

it('refuses a blank phone number', function () {
    expect(fn () => $this->customers->create(phone: '   ', deviceId: DEVICE))
        ->toThrow(CustomerException::class);
});

it('refuses a phone number already registered', function () {
    // Two records on one number would split a household's history in half, and
    // neither half would be complete.
    expect(fn () => $this->customers->create(phone: '0300-1234567', deviceId: DEVICE, name: 'Someone Else'))
        ->toThrow(CustomerException::class);
});

it('trims the phone number', function () {
    $customer = $this->customers->create(phone: '  0321-9998887  ', deviceId: DEVICE);

    // An untrimmed number would not match the same number typed cleanly, and
    // the household would silently get a second record.
    expect($customer->phone_number)->toBe('0321-9998887')
        ->and($this->customers->findByPhone('0321-9998887')->uuid)->toBe($customer->uuid);
});

it('finds a customer by phone number', function () {
    expect($this->customers->findByPhone('0300-1234567')->uuid)->toBe($this->customer->uuid)
        ->and($this->customers->findByPhone('0300-0000000'))->toBeNull()
        ->and($this->customers->findByPhone('  '))->toBeNull();
});

it('refuses to edit the phone number', function () {
    // It is the identity. Editing it would move one household's entire history
    // onto another number with nothing to say it had happened.
    expect(fn () => $this->customers->update($this->customer->uuid, ['phone_number' => '0300-9999999']))
        ->toThrow(CustomerException::class);
});

it('edits the mutable fields', function () {
    $this->customers->update($this->customer->uuid, [
        'primary_contact_name' => 'Fatima Khan',
        'address' => '12 Mall Road',
        'email' => 'fatima@example.com',
    ]);

    expect($this->customer->fresh()->primary_contact_name)->toBe('Fatima Khan')
        ->and($this->customer->fresh()->address)->toBe('12 Mall Road');
});

it('refuses an unknown field or an unknown customer on edit', function () {
    expect(fn () => $this->customers->update($this->customer->uuid, ['nope' => 1]))
        ->toThrow(CustomerException::class);

    expect(fn () => $this->customers->update('no-such-customer', ['city' => 'Lahore']))
        ->toThrow(CustomerException::class);
});

// ---------------------------------------------------------------------------
// find or create - the counter path
// ---------------------------------------------------------------------------

it('finds an existing customer rather than creating a second', function () {
    [$found, $created] = $this->customers->findOrCreate('0300-1234567', DEVICE);

    expect($created)->toBeFalse()
        ->and($found->uuid)->toBe($this->customer->uuid)
        ->and(Customer::count())->toBe(1);
});

it('creates a customer from a number never seen before', function () {
    // No separate "is this a new customer?" step to answer while a queue waits.
    [$new, $created] = $this->customers->findOrCreate('0345-5554443', DEVICE, 'Imran Ali');

    expect($created)->toBeTrue()
        ->and($new->phone_number)->toBe('0345-5554443')
        ->and($new->primary_contact_name)->toBe('Imran Ali');
});

it('does not overwrite a name on file with one given at the counter', function () {
    // The person standing there may be a family member, or may mis-say it.
    [$found] = $this->customers->findOrCreate('0300-1234567', DEVICE, 'Someone Different');

    expect($found->primary_contact_name)->toBe('Fatima Bibi');
});

it('refuses a blank number at the counter', function () {
    expect(fn () => $this->customers->findOrCreate('  ', DEVICE))
        ->toThrow(CustomerException::class);
});

// ---------------------------------------------------------------------------
// listing, and the walk-in sentinel
// ---------------------------------------------------------------------------

it('keeps the walk-in sentinel out of the customer list', function () {
    /*
     * Most sales are to a stranger who will never be seen again. The sentinel
     * lets those be recorded without demanding a phone number, but it is a
     * system row - not a patient - so it must not appear in a list of people.
     */
    Customer::create([
        'phone_number' => Customer::WALK_IN_PHONE,
        'primary_contact_name' => 'Walk-in',
        'origin_device_id' => DEVICE,
    ]);

    expect($this->customers->list()->pluck('phone_number')->all())->toBe(['0300-1234567'])
        // But it is still findable, because a sale needs to point at it.
        ->and($this->customers->walkIn())->not->toBeNull();
});

it('searches by name and by phone number', function () {
    $this->customers->create(phone: '0321-1112223', deviceId: DEVICE, name: 'Imran Ali');

    expect($this->customers->list('imran')->pluck('primary_contact_name')->all())->toBe(['Imran Ali'])
        ->and($this->customers->list('0321')->pluck('primary_contact_name')->all())->toBe(['Imran Ali'])
        // Partial number: staff are read the last four digits over a counter.
        ->and($this->customers->list('2223')->pluck('primary_contact_name')->all())->toBe(['Imran Ali']);
});

it('ignores a search of under two characters', function () {
    // One character matches most of the list, which is not a search.
    expect($this->customers->list('0'))->toHaveCount(1)
        ->and($this->customers->list(''))->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// family members
// ---------------------------------------------------------------------------

it('adds family members under one number', function () {
    // One household collects for several people on one phone number.
    $this->customers->addFamilyMember($this->customer->uuid, DEVICE, 'Zainab', 'daughter');
    $this->customers->addFamilyMember($this->customer->uuid, DEVICE, 'Ahmed', 'son');

    expect($this->customers->familyMembers($this->customer->uuid)->pluck('member_name')->all())
        ->toBe(['Ahmed', 'Zainab']);        // name-sorted
});

it('refuses a blank member name or an unknown customer', function () {
    expect(fn () => $this->customers->addFamilyMember($this->customer->uuid, DEVICE, '  '))
        ->toThrow(CustomerException::class);

    expect(fn () => $this->customers->addFamilyMember('no-such-customer', DEVICE, 'Zainab'))
        ->toThrow(CustomerException::class);
});

it('edits a family member', function () {
    $member = $this->customers->addFamilyMember($this->customer->uuid, DEVICE, 'Zainab', 'daughter');

    $this->customers->updateFamilyMember($member->uuid, [
        'member_name' => 'Zainab Bibi',
        'notes' => 'penicillin allergy',
    ]);

    expect($member->fresh()->member_name)->toBe('Zainab Bibi')
        // The clinically important field, which is why it is editable.
        ->and($member->fresh()->notes)->toBe('penicillin allergy');
});

it('refuses to move a member to another household', function () {
    // customer_uuid is not editable: it would move one person's medication
    // history onto a different family.
    $member = $this->customers->addFamilyMember($this->customer->uuid, DEVICE, 'Zainab');

    expect(fn () => $this->customers->updateFamilyMember($member->uuid, ['customer_uuid' => 'elsewhere']))
        ->toThrow(CustomerException::class);
});

it('refuses a blanked member name on edit', function () {
    $member = $this->customers->addFamilyMember($this->customer->uuid, DEVICE, 'Zainab');

    expect(fn () => $this->customers->updateFamilyMember($member->uuid, ['member_name' => ' ']))
        ->toThrow(CustomerException::class);
});

// ---------------------------------------------------------------------------
// history
// ---------------------------------------------------------------------------

it('lists what a household has bought, newest first', function () {
    $staff = User::factory()->create();
    $item = Item::factory()->create();
    $batch = ItemBatch::factory()->for($item, 'item')->create();
    app(StockService::class)->receive(itemUuid: $item->uuid, batchUuid: $batch->uuid, quantity: 100, deviceId: DEVICE);

    $first = app(SaleService::class)->create(
        lines: [new CartLine($item->uuid, 1, '10.00', $batch->uuid)],
        staffMemberUuid: $staff->uuid, deviceId: DEVICE, customerUuid: $this->customer->uuid,
    );

    $this->travel(1)->minute();

    $second = app(SaleService::class)->create(
        lines: [new CartLine($item->uuid, 2, '10.00', $batch->uuid)],
        staffMemberUuid: $staff->uuid, deviceId: DEVICE, customerUuid: $this->customer->uuid,
    );

    expect($this->customers->sales($this->customer->uuid)->pluck('uuid')->all())
        ->toBe([$second->uuid, $first->uuid]);
});

it('lists what one member has been taking', function () {
    // A drug interaction is per person, not per phone number, which is the whole
    // reason family members exist as rows.
    $staff = User::factory()->create();
    $item = Item::factory()->create();
    $batch = ItemBatch::factory()->for($item, 'item')->create();
    app(StockService::class)->receive(itemUuid: $item->uuid, batchUuid: $batch->uuid, quantity: 100, deviceId: DEVICE);

    $mother = $this->customers->addFamilyMember($this->customer->uuid, DEVICE, 'Amina', 'mother');
    $son = $this->customers->addFamilyMember($this->customer->uuid, DEVICE, 'Ahmed', 'son');

    app(SaleService::class)->create(
        lines: [new CartLine($item->uuid, 1, '10.00', $batch->uuid)],
        staffMemberUuid: $staff->uuid, deviceId: DEVICE,
        customerUuid: $this->customer->uuid, familyMemberUuid: $mother->uuid,
    );

    expect($this->customers->memberSales($mother->uuid))->toHaveCount(1)
        ->and($this->customers->memberSales($son->uuid))->toHaveCount(0)
        // The household view still shows it.
        ->and($this->customers->sales($this->customer->uuid))->toHaveCount(1);
});

it('keeps a returned sale in the history', function () {
    // "We returned that" is part of the history a pharmacist needs, not an
    // embarrassment to be tidied away.
    $staff = User::factory()->create();
    $item = Item::factory()->create();
    $batch = ItemBatch::factory()->for($item, 'item')->create();
    app(StockService::class)->receive(itemUuid: $item->uuid, batchUuid: $batch->uuid, quantity: 100, deviceId: DEVICE);

    $sale = app(SaleService::class)->create(
        lines: [new CartLine($item->uuid, 2, '10.00', $batch->uuid)],
        staffMemberUuid: $staff->uuid, deviceId: DEVICE, customerUuid: $this->customer->uuid,
    );

    app(ReturnService::class)->create(
        originalSaleUuid: $sale->uuid,
        lines: [new ReturnLine($sale->lines()->first()->uuid, 2)],
        staffMemberUuid: $staff->uuid, deviceId: DEVICE,
    );

    $history = $this->customers->sales($this->customer->uuid);

    expect($history)->toHaveCount(1)
        ->and($history->first()->is_returned)->toBeTrue();
});

it('returns an empty history for a customer who has bought nothing', function () {
    expect($this->customers->sales($this->customer->uuid))->toBeEmpty();
});
