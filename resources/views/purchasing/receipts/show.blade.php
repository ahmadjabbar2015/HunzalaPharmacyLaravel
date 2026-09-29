@extends('layouts.app')

@section('title', $receipt->purchase_number)

@section('content')

<div class="mb-3">
    <h1 class="h4 mb-1">{{ $receipt->purchase_number }}</h1>
    <p class="text-secondary small mb-0">
        Received {{ $receipt->purchase_date->format('j M Y') }} from
        <a href="{{ route('suppliers.show', $receipt->supplier_uuid) }}">
            {{ $receipt->supplier?->supplier_name ?? '—' }}
        </a>
        @if ($receipt->purchaseOrder)
            &middot; against
            <a href="{{ route('purchasing.orders.show', $receipt->purchaseOrder) }}">
                {{ $receipt->purchaseOrder->po_number }}
            </a>
        @else
            &middot; no purchase order
        @endif
        @if ($receipt->invoice_reference)
            &middot; invoice {{ $receipt->invoice_reference }}
        @endif
    </p>
</div>

<div class="card">
    <div class="card-header">What arrived</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Batch</th>
                    <th>Expires</th>
                    <th class="text-end">Quantity</th>
                    <th class="text-end">Unit cost</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td>
                            @if ($line->item)
                                <a href="{{ route('inventory.show', $line->item) }}">{{ $line->item->item_name }}</a>
                                <span class="small text-secondary d-block">{{ $line->item->item_code }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $line->batch?->batch_number ?? '—' }}</td>
                        <td class="small">{{ $line->batch?->expiry_date?->format('j M Y') ?? '—' }}</td>
                        <td class="qty">{{ $line->quantity }}</td>
                        <td class="money">{{ $line->unit_cost }}</td>
                        <td class="money">{{ $line->amount }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-semibold">
                    <td colspan="5" class="text-end">Total</td>
                    <td class="money">{{ $receipt->total_amount }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="card-footer small text-secondary">
        Each line created a batch and a positive ledger entry, so this stock is on the
        shelf and its expiry is tracked. The value has been added to what the shop owes
        this supplier.
    </div>
</div>

@endsection
