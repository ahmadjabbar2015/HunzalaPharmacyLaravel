@extends('layouts.app')

@section('title', $customer->primary_contact_name ?? $customer->phone_number)

@section('content')

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">
            {{ $customer->primary_contact_name ?? $customer->phone_number }}
            @unless ($customer->is_active)
                <span class="badge text-bg-secondary">inactive</span>
            @endunless
        </h1>
        <p class="text-secondary small mb-0">
            {{ $customer->phone_number }}
            @if ($customer->city) &middot; {{ $customer->city }} @endif
            @if ($customer->email) &middot; {{ $customer->email }} @endif
        </p>
    </div>
    <a href="{{ route('customers.edit', $customer) }}" class="btn btn-outline-secondary">Edit</a>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card mb-3">
            <div class="card-header">Family</div>

            <ul class="list-group list-group-flush">
                @forelse ($members as $member)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <a href="{{ route('customers.members.show', $member) }}">{{ $member->member_name }}</a>
                            <span class="small text-secondary d-block">
                                {{ $member->relationship_type ?? 'unspecified' }}
                                @if ($member->dob)
                                    &middot; born {{ $member->dob->format('j M Y') }}
                                    ({{ $member->dob->age }})
                                @endif
                            </span>
                            @if ($member->notes)
                                {{-- Allergies and warnings live here, so they are shown, not hidden behind a click. --}}
                                <span class="small text-danger d-block">{{ $member->notes }}</span>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="list-group-item text-secondary small">
                        Nobody added yet. Add family members so medicine history is per person
                        rather than per phone number — a drug interaction is per patient.
                    </li>
                @endforelse
            </ul>

            <div class="card-body border-top">
                <form method="POST" action="{{ route('customers.members.store', $customer) }}">
                    @csrf
                    <div class="row g-2">
                        <div class="col-12 col-md-7">
                            <label for="member_name" class="form-label small mb-1">Name</label>
                            <input type="text" class="form-control form-control-sm @error('member_name') is-invalid @enderror"
                                   id="member_name" name="member_name" value="{{ old('member_name') }}" required>
                            @error('member_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-5">
                            <label for="relationship_type" class="form-label small mb-1">Relationship</label>
                            <select class="form-select form-select-sm" id="relationship_type" name="relationship_type">
                                <option value="">—</option>
                                @foreach (config('pharmacy.relationships') as $relationship)
                                    <option value="{{ $relationship }}" @selected(old('relationship_type') === $relationship)>
                                        {{ ucfirst($relationship) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="dob" class="form-label small mb-1">Born</label>
                            <input type="date" class="form-control form-control-sm @error('dob') is-invalid @enderror"
                                   id="dob" name="dob" value="{{ old('dob') }}">
                            @error('dob')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label for="notes" class="form-label small mb-1">Notes</label>
                            <input type="text" class="form-control form-control-sm" id="notes" name="notes"
                                   value="{{ old('notes') }}" placeholder="allergies">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Add a family member</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        @if ($customer->address)
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 text-secondary">Address</h2>
                    <p class="small mb-0">{{ $customer->address }}</p>
                </div>
            </div>
        @endif
    </div>

    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header">Purchase history</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Invoice</th>
                            <th>For</th>
                            <th>What</th>
                            <th class="text-end">Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sales as $sale)
                            <tr>
                                <td class="small">
                                    {{ \App\Support\BusinessDate::toLocal($sale->sale_time)->format('j M y') }}
                                </td>
                                <td class="small">
                                    <a href="{{ route('sales.show', $sale) }}">{{ $sale->invoice_number }}</a>
                                    @if ($sale->is_returned)
                                        {{--
                                            Shown, not hidden. "We returned that" is part
                                            of the history a pharmacist needs.
                                        --}}
                                        <span class="badge text-bg-warning">returned</span>
                                    @endif
                                </td>
                                <td class="small">{{ $sale->familyMember?->member_name ?? '—' }}</td>
                                <td class="small">
                                    {{ $sale->lines->take(3)->map(fn ($l) => $l->item?->item_name)->filter()->join(', ') }}
                                    @if ($sale->lines->count() > 3)
                                        <span class="text-secondary">+{{ $sale->lines->count() - 3 }} more</span>
                                    @endif
                                </td>
                                <td class="money">{{ $sale->net_amount }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-secondary py-4 text-center">
                                    Nothing bought under this number yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
