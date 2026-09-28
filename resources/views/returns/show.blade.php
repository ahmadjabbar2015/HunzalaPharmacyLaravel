@extends('layouts.app')

@section('title', $return->return_number)

@section('content')

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">{{ $return->return_number }}</h1>
        <p class="text-secondary small mb-0">
            {{ \App\Support\BusinessDate::toLocal($return->return_time)->format('j M Y, g:ia') }}
            &middot; by {{ $return->staffMember?->full_name ?? '—' }}
            @if ($return->originalSale)
                &middot; against
                <a href="{{ route('sales.show', $return->originalSale) }}">
                    {{ $return->originalSale->invoice_number }}
                </a>
            @endif
        </p>
    </div>
    <a href="{{ route('returns.receipt', $return) }}" class="btn btn-outline-secondary" target="_blank">
        Print the slip
    </a>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header">What came back</div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Refunded</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($return->lines as $line)
                            <tr>
                                <td>
                                    {{ $line->item?->item_name ?? '—' }}
                                    @if ($line->batch)
                                        <span class="small text-secondary d-block">
                                            back into batch {{ $line->batch->batch_number }}
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
                        <tr class="fw-semibold">
                            <td colspan="3" class="text-end">Total refunded</td>
                            <td class="money">{{ $return->total_amount }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-5">Refunded by</dt>
                    <dd class="col-7">{{ $return->refund_method }}</dd>

                    @if ($return->payment_reference)
                        <dt class="col-5">Reference</dt>
                        <dd class="col-7">{{ $return->payment_reference }}</dd>
                    @endif

                    <dt class="col-5">Customer</dt>
                    <dd class="col-7">
                        @if ($return->customer)
                            <a href="{{ route('customers.show', $return->customer) }}">
                                {{ $return->customer->primary_contact_name ?? $return->customer->phone_number }}
                            </a>
                        @else
                            walk-in
                        @endif
                    </dd>
                </dl>

                <hr>

                <p class="small text-secondary mb-0">
                    The original sale's amounts were not changed. This return is its own
                    record, and the stock went back into the batch it left from so its
                    expiry stays correct.
                </p>
            </div>
        </div>
    </div>
</div>

@endsection
