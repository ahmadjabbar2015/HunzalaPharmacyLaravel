@extends('layouts.app')

@section('title', $order->po_number)

@section('content')

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">
            {{ $order->po_number }}
            <span class="badge text-bg-{{ match ($order->status) {
                'received' => 'success',
                'partially_received' => 'warning',
                'cancelled' => 'secondary',
                default => 'primary',
            } }}">
                {{ str_replace('_', ' ', $order->status) }}
            </span>
        </h1>
        <p class="text-secondary small mb-0">
            Ordered {{ $order->order_date->format('j M Y') }} from
            <a href="{{ route('suppliers.show', $order->supplier_uuid) }}">
                {{ $order->supplier?->supplier_name ?? '—' }}
            </a>
        </p>
    </div>

    <div class="d-flex gap-2">
        @if (! in_array($order->status, ['received', 'cancelled'], true))
            <a href="{{ route('purchasing.orders.receive.form', $order) }}" class="btn btn-success">
                Receive a delivery
            </a>

            <form method="POST" action="{{ route('purchasing.orders.cancel', $order) }}"
                  onsubmit="return confirm('Cancel {{ $order->po_number }}?')">
                @csrf
                <button type="submit" class="btn btn-outline-danger">Cancel the order</button>
            </form>
        @endif
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header">On order</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-end">Ordered</th>
                            <th class="text-end">Received</th>
                            <th class="text-end">Still due</th>
                            <th class="text-end">Cost per piece</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            @php($due = $line->quantity_ordered - $line->quantity_received)
                            <tr>
                                <td>
                                    @if ($line->item)
                                        <a href="{{ route('inventory.show', $line->item) }}">
                                            {{ $line->item->item_name }}
                                        </a>
                                        <span class="small text-secondary d-block">{{ $line->item->item_code }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="qty">{{ $line->quantity_ordered }}</td>
                                <td class="qty">{{ $line->quantity_received }}</td>
                                <td class="qty {{ $due > 0 ? 'fw-semibold' : 'text-secondary' }}">
                                    {{ $due > 0 ? $due : '—' }}
                                </td>
                                <td class="money">{{ $line->unit_cost }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-secondary">
                {{--
                    Said out loud because it is the distinction the whole screen
                    turns on: an order is a commitment, not stock.
                --}}
                These quantities are a commitment to the supplier. Nothing is on the
                shelf until a delivery is received against this order.
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        @if ($order->notes)
            <div class="card mb-3">
                <div class="card-body">
                    <h2 class="h6 text-secondary">Notes</h2>
                    <p class="small mb-0">{{ $order->notes }}</p>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header">Deliveries against this order</div>
            <ul class="list-group list-group-flush">
                @forelse ($receipts as $receipt)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <a href="{{ route('purchasing.receipts.show', $receipt) }}">
                                {{ $receipt->purchase_number }}
                            </a>
                            <span class="small text-secondary d-block">
                                {{ $receipt->purchase_date->format('j M y') }}
                                @if ($receipt->invoice_reference)
                                    &middot; {{ $receipt->invoice_reference }}
                                @endif
                            </span>
                        </div>
                        <span class="money">{{ $receipt->total_amount }}</span>
                    </li>
                @empty
                    <li class="list-group-item text-secondary small">
                        Nothing delivered yet. Suppliers routinely split a delivery, so
                        an order can be received more than once.
                    </li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

@endsection
