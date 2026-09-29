@extends('layouts.app')

@section('title', 'Suppliers')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Suppliers</h1>
    <a href="{{ route('suppliers.create') }}" class="btn btn-primary">Add a supplier</a>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('suppliers.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-7">
                <label for="q" class="form-label small mb-1">Name</label>
                <input type="text" class="form-control form-control-sm" id="q" name="q"
                       value="{{ $filters['q'] ?? '' }}">
            </div>
            <div class="col-6 col-md-3">
                <label for="show" class="form-label small mb-1">Show</label>
                <select class="form-select form-select-sm" id="show" name="show">
                    <option value="active" @selected(($filters['show'] ?? 'active') === 'active')>Active</option>
                    <option value="all" @selected(($filters['show'] ?? '') === 'all')>Including dormant</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Contact</th>
                    <th>Terms</th>
                    <th class="text-end">Owed</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($suppliers as $supplier)
                    @php($balance = $balances[$supplier->uuid] ?? '0.00')
                    <tr class="{{ $supplier->is_active ? '' : 'text-secondary' }}">
                        <td>
                            <a href="{{ route('suppliers.show', $supplier) }}" class="fw-semibold">
                                {{ $supplier->supplier_name }}
                            </a>
                            @unless ($supplier->is_active)
                                <span class="badge text-bg-light">dormant</span>
                            @endunless
                        </td>
                        <td class="small">
                            {{ $supplier->contact_person ?? '—' }}
                            @if ($supplier->phone)
                                <span class="d-block text-secondary">{{ $supplier->phone }}</span>
                            @endif
                        </td>
                        <td class="small">{{ $supplier->payment_terms ?? '—' }}</td>
                        {{--
                            Derived every time from opening balance plus purchases
                            less payments, never stored. A stored balance drifts,
                            and a drifted one is an argument with someone the shop
                            has to keep buying from.
                        --}}
                        <td class="money {{ \App\Support\Money::isNegative($balance) ? 'text-success' : '' }}">
                            {{ $balance }}
                        </td>
                        <td class="text-end">
                            <a href="{{ route('suppliers.show', $supplier) }}"
                               class="btn btn-sm btn-outline-secondary">Account</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-secondary py-4 text-center">No suppliers yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="small text-secondary mt-3">
    A negative balance means the shop is in credit with that supplier.
</p>

@endsection
