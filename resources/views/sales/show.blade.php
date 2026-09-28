@extends('layouts.app')

@section('title', $sale->invoice_number)

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">
        {{ $sale->invoice_number }}
        @if ($sale->is_returned)
            <span class="badge text-bg-warning">returned</span>
        @endif
    </h1>
    <div class="d-flex gap-2">
        <a href="{{ route('sales.receipt', $sale) }}" class="btn btn-outline-secondary" target="_blank">
            Receipt
        </a>
        <a href="{{ route('sales.index') }}" class="btn btn-link">Back to sales</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header">Items</div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Batch</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sale->lines as $line)
                            <tr>
                                <td>
                                    {{ $line->item?->item_name ?? '—' }}
                                    <span class="small text-secondary d-block">{{ $line->item?->item_code }}</span>
                                </td>
                                <td class="small">
                                    {{-- The batch is recorded so a recall can be traced. --}}
                                    {{ $line->batch?->batch_number ?? '—' }}
                                    @if ($line->batch)
                                        <span class="d-block text-secondary">
                                            exp {{ $line->batch->expiry_date->format('M Y') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="qty">{{ $line->quantity }}</td>
                                <td class="money">{{ $line->rate }}</td>
                                <td class="money">{{ $line->amount }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" class="text-end">Subtotal</th>
                            <td class="money">{{ $sale->subtotal_amount }}</td>
                        </tr>
                        @if (! \App\Support\Money::isZero($sale->discount_amount))
                            <tr>
                                <th colspan="4" class="text-end">
                                    Discount
                                    @if ($sale->discount_reason)
                                        <span class="fw-normal small text-secondary">
                                            — {{ $sale->discount_reason }}
                                        </span>
                                    @endif
                                </th>
                                <td class="money text-danger">−{{ $sale->discount_amount }}</td>
                            </tr>
                        @endif
                        <tr>
                            <th colspan="4" class="text-end h6">Net</th>
                            <td class="money h6">{{ $sale->net_amount }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        @if ($sale->returns->isNotEmpty())
            {{--
                Returns are shown against the sale but the sale's own figures
                above are untouched: the history of what was sold stays true even
                after some of it came back.
            --}}
            <div class="card mt-3">
                <div class="card-header">Returned against this sale</div>
                <ul class="list-group list-group-flush">
                    @foreach ($sale->returns as $return)
                        <li class="list-group-item d-flex justify-content-between">
                            <span>
                                {{ $return->return_number }}
                                <span class="small text-secondary">
                                    {{ $return->return_date->format('j M Y') }}
                                    &middot; {{ $return->lines->sum('quantity') }} units
                                    &middot; refunded by {{ $return->refund_method }}
                                </span>
                            </span>
                            <span class="money">{{ $return->total_amount }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">When</dt>
                    <dd class="col-7">
                        {{ \App\Support\BusinessDate::toLocal($sale->sale_time)->format('j M Y, g:ia') }}
                    </dd>

                    <dt class="col-5">Business date</dt>
                    <dd class="col-7">
                        {{ $sale->sale_date->format('j M Y') }}
                        {{--
                            Shown separately from the timestamp, because a sale
                            after midnight local belongs to that day's takings and
                            the two will legitimately differ.
                        --}}
                    </dd>

                    <dt class="col-5">Served by</dt>
                    <dd class="col-7">{{ $sale->staffMember?->full_name ?? '—' }}</dd>

                    <dt class="col-5">Customer</dt>
                    <dd class="col-7">
                        {{ $sale->customer?->primary_contact_name ?? $sale->customer?->phone_number ?? 'Walk-in' }}
                        @if ($sale->familyMember)
                            <span class="d-block text-secondary">for {{ $sale->familyMember->member_name }}</span>
                        @endif
                    </dd>

                    <dt class="col-5">Paid by</dt>
                    <dd class="col-7">
                        {{ ucfirst($sale->payment_method ?? '—') }}
                        @if ($sale->payment_reference)
                            <span class="d-block text-secondary">{{ $sale->payment_reference }}</span>
                        @endif
                    </dd>

                    @if ($sale->discount_pin_verified)
                        <dt class="col-5">Discount</dt>
                        <dd class="col-7">Authorised by PIN</dd>
                    @endif

                    <dt class="col-5">Receipts printed</dt>
                    <dd class="col-7">{{ $sale->print_count }}</dd>

                    <dt class="col-5">Till</dt>
                    <dd class="col-7"><code>{{ $sale->origin_device_id }}</code></dd>
                </dl>
            </div>
        </div>

        <p class="small text-secondary mt-3">
            A sale is never edited. A mistake is corrected with a return, which
            records its own compensating stock movements.
        </p>
    </div>
</div>

@endsection
