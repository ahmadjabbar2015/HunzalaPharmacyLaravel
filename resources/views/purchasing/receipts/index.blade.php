@extends('layouts.app')

@section('title', 'Goods receipts')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Goods receipts</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('purchasing.orders.index') }}" class="btn btn-outline-secondary">Purchase orders</a>
        <a href="{{ route('purchasing.receipts.create') }}" class="btn btn-primary">Record a delivery</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('purchasing.receipts.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-8">
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
            <div class="col-12 col-md-4">
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
                    <th>Date</th>
                    <th>Receipt</th>
                    <th>Supplier</th>
                    <th>Against</th>
                    <th>Invoice</th>
                    <th class="text-end">Value</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($receipts as $receipt)
                    <tr>
                        <td class="small">{{ $receipt->purchase_date->format('j M Y') }}</td>
                        <td>
                            <a href="{{ route('purchasing.receipts.show', $receipt) }}">
                                {{ $receipt->purchase_number }}
                            </a>
                        </td>
                        <td class="small">{{ $receipt->supplier?->supplier_name ?? '—' }}</td>
                        <td class="small">
                            @if ($receipt->purchaseOrder)
                                <a href="{{ route('purchasing.orders.show', $receipt->purchaseOrder) }}">
                                    {{ $receipt->purchaseOrder->po_number }}
                                </a>
                            @else
                                {{-- No order behind it: a walk-in delivery. --}}
                                <span class="text-secondary">direct</span>
                            @endif
                        </td>
                        <td class="small text-secondary">{{ $receipt->invoice_reference ?? '—' }}</td>
                        <td class="money">{{ $receipt->total_amount }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-secondary py-4 text-center">Nothing received yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
