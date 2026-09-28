@extends('layouts.app')

@section('title', $member->member_name)

@section('content')

<div class="mb-3">
    <p class="small text-secondary mb-1">
        <a href="{{ route('customers.show', $customer) }}">
            {{ $customer->primary_contact_name ?? $customer->phone_number }}
        </a>
        &rsaquo;
    </p>
    <h1 class="h4 mb-0">
        {{ $member->member_name }}
        @if ($member->relationship_type)
            <span class="badge text-bg-secondary">{{ $member->relationship_type }}</span>
        @endif
    </h1>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-4">
        @if ($member->notes)
            {{--
                Allergies and warnings, at the top and in red. This is the one
                thing on the screen that could stop a mistake being made.
            --}}
            <div class="alert alert-danger">
                <h2 class="h6">Notes</h2>
                <p class="mb-0 small">{{ $member->notes }}</p>
            </div>
        @endif

        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <form method="POST" action="{{ route('customers.members.update', $member) }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label for="member_name" class="form-label">Name</label>
                        <input type="text" class="form-control @error('member_name') is-invalid @enderror"
                               id="member_name" name="member_name"
                               value="{{ old('member_name', $member->member_name) }}" required maxlength="120">
                        @error('member_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="relationship_type" class="form-label">Relationship</label>
                        <select class="form-select" id="relationship_type" name="relationship_type">
                            <option value="">—</option>
                            @foreach (config('pharmacy.relationships') as $relationship)
                                <option value="{{ $relationship }}"
                                    @selected(old('relationship_type', $member->relationship_type) === $relationship)>
                                    {{ ucfirst($relationship) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="dob" class="form-label">Born</label>
                        <input type="date" class="form-control @error('dob') is-invalid @enderror"
                               id="dob" name="dob"
                               value="{{ old('dob', $member->dob?->toDateString()) }}">
                        @error('dob')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Paediatric doses depend on it.</div>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes"
                                  rows="3" placeholder="allergies, conditions, things to watch">{{ old('notes', $member->notes) }}</textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header">
                What {{ $member->member_name }} has been taking
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Medicine</th>
                            <th class="text-end">Qty</th>
                            <th>Invoice</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{--
                            Flattened to one row per LINE rather than per sale: the
                            clinical question is what this person is on, and a sale
                            of four things is four separate answers.
                        --}}
                        @forelse ($sales as $sale)
                            @foreach ($sale->lines as $line)
                                <tr>
                                    <td class="small">
                                        {{ \App\Support\BusinessDate::toLocal($sale->sale_time)->format('j M y') }}
                                    </td>
                                    <td class="small">{{ $line->item?->item_name ?? '—' }}</td>
                                    <td class="qty">{{ $line->quantity }}</td>
                                    <td class="small">
                                        <a href="{{ route('sales.show', $sale) }}">{{ $sale->invoice_number }}</a>
                                        @if ($sale->is_returned)
                                            <span class="badge text-bg-warning">returned</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="4" class="text-secondary py-4 text-center">
                                    Nothing recorded against this person yet.
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
