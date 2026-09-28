<?php

namespace App\Http\Controllers;

use App\Exceptions\CustomerException;
use App\Models\Customer;
use App\Models\FamilyMember;
use App\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/*
 * Customers and the families under them.
 *
 * There is no delete route. A customer's history is the reason the record
 * exists, and removing the row would orphan every sale pointing at it; a
 * household that has stopped coming is marked inactive instead.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return view('customers.index', [
            'customers' => $this->customers->list($filters['q'] ?? null),
            'search' => $filters['q'] ?? '',
        ]);
    }

    public function show(Customer $customer): View
    {
        return view('customers.show', [
            'customer' => $customer,
            'members' => $this->customers->familyMembers($customer->uuid),
            'sales' => $this->customers->sales($customer->uuid),
        ]);
    }

    public function create(): View
    {
        return view('customers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:30'],
            'primary_contact_name' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);

        try {
            $customer = $this->customers->create(
                phone: $validated['phone_number'],
                deviceId: config('pharmacy.device_id'),
                name: $validated['primary_contact_name'] ?? null,
                address: $validated['address'] ?? null,
                city: $validated['city'] ?? null,
                email: $validated['email'] ?? null,
            );
        } catch (CustomerException $e) {
            return back()->withInput()->withErrors(['phone_number' => $e->getMessage()]);
        }

        return redirect()->route('customers.show', $customer)->with('status', 'Customer added.');
    }

    public function edit(Customer $customer): View
    {
        return view('customers.edit', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'primary_contact_name' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);

        // phone_number is deliberately absent: it is the identity, and changing
        // it would move the household's whole history onto another number.
        $validated['is_active'] = $request->boolean('is_active');

        try {
            $this->customers->update($customer->uuid, $validated);
        } catch (CustomerException $e) {
            return back()->withInput()->withErrors(['primary_contact_name' => $e->getMessage()]);
        }

        return redirect()->route('customers.show', $customer)->with('status', 'Customer updated.');
    }

    // -----------------------------------------------------------------------
    // family members
    // -----------------------------------------------------------------------

    public function storeMember(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'member_name' => ['required', 'string', 'max:120'],
            'relationship_type' => ['nullable', Rule::in(config('pharmacy.relationships'))],
            // A date of birth in the future is a typo, and paediatric dosing is
            // one of the things this field is for.
            'dob' => ['nullable', 'date', 'before:tomorrow'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->customers->addFamilyMember(
                customerUuid: $customer->uuid,
                deviceId: config('pharmacy.device_id'),
                memberName: $validated['member_name'],
                relationshipType: $validated['relationship_type'] ?? null,
                dob: $validated['dob'] ?? null,
                notes: $validated['notes'] ?? null,
            );
        } catch (CustomerException $e) {
            return back()->withInput()->withErrors(['member_name' => $e->getMessage()]);
        }

        return redirect()->route('customers.show', $customer)->with('status', 'Family member added.');
    }

    public function updateMember(Request $request, FamilyMember $member): RedirectResponse
    {
        $validated = $request->validate([
            'member_name' => ['required', 'string', 'max:120'],
            'relationship_type' => ['nullable', Rule::in(config('pharmacy.relationships'))],
            'dob' => ['nullable', 'date', 'before:tomorrow'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->customers->updateFamilyMember($member->uuid, $validated);
        } catch (CustomerException $e) {
            return back()->withInput()->withErrors(['member_name' => $e->getMessage()]);
        }

        return redirect()->route('customers.show', $member->customer_uuid)
            ->with('status', 'Family member updated.');
    }

    /**
     * What one member of the household has been taking.
     *
     * A separate screen from the household's history, because a drug interaction
     * is per person: the useful question is what THIS patient is on, not what the
     * phone number has bought.
     */
    public function showMember(FamilyMember $member): View
    {
        return view('customers.member', [
            'member' => $member,
            'customer' => $member->customer,
            'sales' => $this->customers->memberSales($member->uuid),
        ]);
    }
}
