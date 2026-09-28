@extends('layouts.app')

@section('title', 'Customers')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Customers</h1>
    <a href="{{ route('customers.create') }}" class="btn btn-primary">Add a customer</a>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('customers.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-8">
                <label for="q" class="form-label small mb-1">Phone number or name</label>
                {{--
                    Searchable by partial number, because staff are read the last
                    four digits across a counter far more often than a full one.
                --}}
                <input type="text" class="form-control" id="q" name="q" value="{{ $search }}"
                       placeholder="0300 or Fatima" autofocus>
            </div>
            <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-primary w-100">Search</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Phone</th>
                    <th>Name</th>
                    <th>City</th>
                    <th class="text-end">Family</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($customers as $customer)
                    <tr class="{{ $customer->is_active ? '' : 'text-secondary' }}">
                        <td>
                            <a href="{{ route('customers.show', $customer) }}" class="fw-semibold">
                                {{ $customer->phone_number }}
                            </a>
                            @unless ($customer->is_active)
                                <span class="badge text-bg-light">inactive</span>
                            @endunless
                        </td>
                        <td>{{ $customer->primary_contact_name ?? '—' }}</td>
                        <td class="small">{{ $customer->city ?? '—' }}</td>
                        <td class="text-end small text-secondary">
                            {{ $customer->familyMembers()->count() ?: '—' }}
                        </td>
                        <td class="text-end">
                            <a href="{{ route('customers.show', $customer) }}"
                               class="btn btn-sm btn-outline-secondary">History</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-secondary py-4 text-center">
                            @if ($search)
                                Nothing matches &ldquo;{{ $search }}&rdquo;.
                            @else
                                No customers yet. Most sales are to a walk-in and need no record.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="small text-secondary mt-3">
    The phone number is the customer's identity and cannot be changed later.
    Walk-in sales are not listed here.
</p>

@endsection
