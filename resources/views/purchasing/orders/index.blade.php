@extends('layouts.app')

@section('title', 'Purchase orders')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Purchase orders</h1>
    <a href="{{ route('purchasing.orders.create') }}" class="btn btn-primary">Place an order</a>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('purchasing.orders.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label for="supplier" class="form-label small mb-1">Supplier</label>
                <select class="form-select form-select-sm" id="supplier" name="supplier">
                    <option value="">Any</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->uuid }}" @selected(($filters['supplier'] ?? '') === $supplier->uuid)>
                            {{ $supplier->supplier_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-4">
                <label for="status" class="form-label small mb-1">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Any</option>
                    @foreach (config('pharmacy.po_statuses') as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                            {{ str_replace('_', ' ', ucfirst($status)) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
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
                    <th>Ordered</th>
                    <th>Number</th>
                    <th>Supplier</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td class="small">{{ $order->order_date->format('j M Y') }}</td>
                        <td>
                            <a href="{{ route('purchasing.orders.show', $order) }}">{{ $order->po_number }}</a>
                        </td>
                        <td class="small">
                            <a href="{{ route('suppliers.show', $order->supplier_uuid) }}">
                                {{ $order->supplier?->supplier_name ?? '—' }}
                            </a>
                        </td>
                        <td>
                            {{--
                                The status is derived from what has actually been
                                received, never set by hand, so it cannot claim a
                                delivery that did not arrive.
                            --}}
                            <span class="badge text-bg-{{ match ($order->status) {
                                'received' => 'success',
                                'partially_received' => 'warning',
                                'cancelled' => 'secondary',
                                default => 'primary',
                            } }}">
                                {{ str_replace('_', ' ', $order->status) }}
                            </span>
                        </td>
                        <td class="text-end">
                            @if (! in_array($order->status, ['received', 'cancelled'], true))
                                <a href="{{ route('purchasing.orders.receive.form', $order) }}"
                                   class="btn btn-sm btn-outline-success">Receive</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-secondary py-4 text-center">
                            No orders match that.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="small text-secondary mt-3">
    An order is a plan. No stock moves until a delivery is received against it.
</p>

@endsection
