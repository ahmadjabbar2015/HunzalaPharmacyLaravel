<?php

namespace App\Services;

use App\Exceptions\CustomerException;
use App\Models\Customer;
use App\Models\FamilyMember;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/*
 * Customers and the families under them.
 *
 * A customer IS a phone number. That is the only thing reliably known at a
 * counter - names are spelled three ways and addresses are given once and never
 * again - so the phone number is the identity and the unique key.
 *
 * Family members hang off a customer because one household collects for several
 * people on one number. A sale can be attributed to a member so "what has my
 * mother been taking" is answerable, which matters for interactions.
 *
 * Ported from shared/services/customer_service.py.
 */
class CustomerService
{
    /*
     * The sentinel phone number for a sale to nobody in particular lives on the
     * Customer model, not here - the model is what the rest of the application
     * reaches for, and two copies of a magic string is one too many.
     *
     * Most sales in a pharmacy are to a stranger who will never be seen again,
     * and forcing a phone number out of them would slow every transaction for
     * data nobody uses. That row is a system record, not a patient, and is kept
     * out of the customer list below.
     */

    public const EDITABLE_FIELDS = ['primary_contact_name', 'address', 'city', 'email', 'is_active'];

    public const MEMBER_EDITABLE_FIELDS = ['member_name', 'relationship_type', 'dob', 'notes'];

    // -----------------------------------------------------------------------
    // finding
    // -----------------------------------------------------------------------

    public function walkIn(): ?Customer
    {
        return $this->findByPhone(Customer::WALK_IN_PHONE);
    }

    public function findByPhone(string $phone): ?Customer
    {
        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        return Customer::query()->where('phone_number', $phone)->first();
    }

    /**
     * The customer for this phone number, creating one if it is new.
     *
     * The POS path: a number typed at the counter either finds someone or starts
     * their record, with no separate "is this a new customer?" step to answer
     * while a queue waits.
     *
     * @return array{0: Customer, 1: bool} the customer, and whether it was created
     *
     * @throws CustomerException on a blank phone number
     */
    public function findOrCreate(string $phone, string $deviceId, ?string $name = null): array
    {
        $phone = trim($phone);

        if ($phone === '') {
            throw new CustomerException('phone number cannot be blank');
        }

        $existing = $this->findByPhone($phone);

        if ($existing !== null) {
            // A name given now is not written over one already on file: the
            // person at the counter may be a family member, or may mis-say it.
            return [$existing, false];
        }

        $customer = Customer::create([
            'phone_number' => $phone,
            'primary_contact_name' => $name,
            'origin_device_id' => $deviceId,
        ]);

        Log::info('customer created at the counter', ['phone' => $phone]);

        return [$customer, true];
    }

    // -----------------------------------------------------------------------
    // creating and editing
    // -----------------------------------------------------------------------

    /**
     * @throws CustomerException on a blank or already-registered phone number
     */
    public function create(
        string $phone,
        string $deviceId,
        ?string $name = null,
        ?string $address = null,
        ?string $city = null,
        ?string $email = null,
    ): Customer {
        $phone = trim($phone);

        if ($phone === '') {
            throw new CustomerException('phone number cannot be blank');
        }

        if ($this->findByPhone($phone) !== null) {
            // Two records on one number would split a household's history in
            // half, and neither half would be complete.
            throw new CustomerException("phone {$phone} is already registered");
        }

        return Customer::create([
            'phone_number' => $phone,
            'primary_contact_name' => $name,
            'address' => $address,
            'city' => $city,
            'email' => $email,
            'origin_device_id' => $deviceId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     *
     * @throws CustomerException on an unknown or non-editable field
     */
    public function update(string $customerUuid, array $changes): Customer
    {
        $customer = Customer::find($customerUuid);

        if ($customer === null) {
            throw new CustomerException("customer '{$customerUuid}' not found");
        }

        $unknown = array_diff(array_keys($changes), self::EDITABLE_FIELDS);

        if ($unknown !== []) {
            // phone_number is absent from the editable set: it is the identity,
            // and editing it would silently move one household's entire history
            // onto another number.
            throw new CustomerException('non-editable fields: '.implode(', ', $unknown));
        }

        $customer->fill($changes)->save();

        return $customer;
    }

    /**
     * Active customers, optionally filtered, with the walk-in sentinel excluded.
     *
     * @return Collection<int, Customer>
     */
    public function list(?string $search = null): Collection
    {
        return Customer::query()
            ->where('phone_number', '!=', Customer::WALK_IN_PHONE)
            ->when(
                $search !== null && mb_strlen(trim($search)) >= 2,
                function ($q) use ($search) {
                    $like = '%'.trim($search).'%';

                    $q->where(fn ($q) => $q
                        ->whereLike('phone_number', $like, caseSensitive: false)
                        ->orWhereLike('primary_contact_name', $like, caseSensitive: false));
                }
            )
            ->orderBy('primary_contact_name')
            ->get();
    }

    // -----------------------------------------------------------------------
    // family members
    // -----------------------------------------------------------------------

    /** @return Collection<int, FamilyMember> */
    public function familyMembers(string $customerUuid): Collection
    {
        return FamilyMember::query()
            ->where('customer_uuid', $customerUuid)
            ->orderBy('member_name')
            ->get();
    }

    /**
     * @throws CustomerException on a blank name or an unknown customer
     */
    public function addFamilyMember(
        string $customerUuid,
        string $deviceId,
        string $memberName,
        ?string $relationshipType = null,
        ?string $dob = null,
        ?string $notes = null,
    ): FamilyMember {
        $memberName = trim($memberName);

        if ($memberName === '') {
            throw new CustomerException('member name cannot be blank');
        }

        if (Customer::find($customerUuid) === null) {
            throw new CustomerException("customer '{$customerUuid}' not found");
        }

        return FamilyMember::create([
            'customer_uuid' => $customerUuid,
            'member_name' => $memberName,
            'relationship_type' => $relationshipType,
            'dob' => $dob,
            'notes' => $notes,
            'origin_device_id' => $deviceId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     *
     * @throws CustomerException on an unknown member or a non-editable field
     */
    public function updateFamilyMember(string $memberUuid, array $changes): FamilyMember
    {
        $member = FamilyMember::find($memberUuid);

        if ($member === null) {
            throw new CustomerException("family member '{$memberUuid}' not found");
        }

        $unknown = array_diff(array_keys($changes), self::MEMBER_EDITABLE_FIELDS);

        if ($unknown !== []) {
            throw new CustomerException('non-editable fields: '.implode(', ', $unknown));
        }

        if (array_key_exists('member_name', $changes) && trim((string) $changes['member_name']) === '') {
            throw new CustomerException('member name cannot be blank');
        }

        $member->fill($changes)->save();

        return $member;
    }

    // -----------------------------------------------------------------------
    // history
    // -----------------------------------------------------------------------

    /**
     * Everything this household has bought, newest first.
     *
     * Returned sales are included and flagged rather than hidden: "we returned
     * that" is part of the history a pharmacist needs, not an embarrassment to
     * be tidied away.
     *
     * @return Collection<int, Sale>
     */
    public function sales(string $customerUuid, int $limit = 100): Collection
    {
        return Sale::query()
            ->where('customer_uuid', $customerUuid)
            ->with(['lines.item', 'familyMember'])
            ->latest('sale_time')
            ->limit($limit)
            ->get();
    }

    /**
     * What one member of the household has been taking.
     *
     * The clinically useful view: a drug interaction is per person, not per
     * phone number.
     *
     * @return Collection<int, Sale>
     */
    public function memberSales(string $memberUuid, int $limit = 100): Collection
    {
        return Sale::query()
            ->where('family_member_uuid', $memberUuid)
            ->with(['lines.item'])
            ->latest('sale_time')
            ->limit($limit)
            ->get();
    }
}
