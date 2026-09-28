@extends('layouts.app')

@section('title', 'Sales')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Sales</h1>
    <span class="text-secondary">
        {{ number_format($sales->total()) }} {{ Str::plural('sale', $sales->total()) }}
        &middot; <span class="money fw-semibold">{{ $filteredTotal }}</span>
    </span>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('sales.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label for="q" class="form-label small mb-1">Invoice or phone</label>
                <input type="text" class="form-control form-control-sm" id="q" name="q"
                       value="{{ $filters['q'] ?? '' }}" placeholder="WEB-20260927-0001">
            </div>
            <div class="col-6 col-md-2">
                <label for="from" class="form-label small mb-1">From</label>
                <input type="date" class="form-control form-control-sm" id="from" name="from"
                       value="{{ $filters['from'] ?? '' }}">
            </div>
            <div class="col-6 col-md-2">
                <label for="to" class="form-label small mb-1">To</label>
                <input type="date" class="form-control form-control-sm" id="to" name="to"
                       value="{{ $filters['to'] ?? '' }}">
            </div>
            <div class="col-6 col-md-2">
                <label for="payment_method" class="form-label small mb-1">Paid by</label>
                <select class="form-select form-select-sm" id="payment_method" name="payment_method">
                    <option value="">Any</option>
                    @foreach (config('pharmacy.payment_methods') as $method)
                        <option value="{{ $method }}" @selected(($filters['payment_method'] ?? '') === $method)>
                            {{ ucfirst($method) }}
                        </option>
                    @endforeach
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
                    <th>Invoice</th>
                    <th>When</th>
                    <th>Served by</th>
                    <th>Customer</th>
                    <th>Paid</th>
                    <th class="text-end">Discount</th>
                    <th class="text-end">Net</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sales as $sale)
                    <tr>
                        <td>
                            <a href="{{ route('sales.show', $sale) }}">{{ $sale->invoice_number }}</a>
                            @if ($sale->is_returned)
                                {{--
                                    Flagged on the list, because a returned sale
                                    should never be read as clean takings.
                                --}}
                                <span class="badge text-bg-warning">returned</span>
                            @endif
                        </td>
                        <td class="small">
                            {{ \App\Support\BusinessDate::toLocal($sale->sale_time)->format('j M Y, g:ia') }}
                        </td>
                        <td class="small">{{ $sale->staffMember?->full_name ?? '—' }}</td>
                        <td class="small">
                            {{ $sale->customer?->primary_contact_name ?? $sale->customer?->phone_number ?? '—' }}
                        </td>
                        <td class="small">
                            {{ ucfirst($sale->payment_method ?? '—') }}
                        </td>
                        <td class="money small">
                            {{ \App\Support\Money::isZero($sale->discount_amount) ? '' : $sale->discount_amount }}
                        </td>
                        <td class="money fw-semibold">{{ $sale->net_amount }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-secondary py-4 text-center">No sales match that.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">
    {{ $sales->links() }}
</div>

@endsection
