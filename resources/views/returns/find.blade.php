@extends('layouts.app')

@section('title', 'Process a return')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Process a return</h1>
    <a href="{{ route('returns.index') }}" class="btn btn-outline-secondary">Past returns</a>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">By invoice number</div>
            <div class="card-body">
                <form method="GET" action="{{ route('returns.find') }}">
                    <div class="mb-3">
                        <label for="invoice" class="form-label">Invoice number</label>
                        <input type="text" class="form-control form-control-lg" id="invoice" name="invoice"
                               value="{{ $filters['invoice'] ?? '' }}"
                               placeholder="WEB-20260928-0014" autofocus spellcheck="false">
                        <div class="form-text">From the customer's receipt.</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Find the sale</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">By phone number</div>
            <div class="card-body">
                <form method="GET" action="{{ route('returns.find') }}">
                    <div class="mb-3">
                        <label for="phone" class="form-label">Customer's phone</label>
                        <input type="text" inputmode="tel" class="form-control form-control-lg"
                               id="phone" name="phone" value="{{ $filters['phone'] ?? '' }}"
                               placeholder="0300-1234567">
                        {{--
                            The usual path. Receipts get lost; phone numbers do not.
                        --}}
                        <div class="form-text">
                            For when the receipt is gone, which is most of the time. Only works
                            if the sale was recorded against a customer.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Find their sales</button>
                </form>
            </div>
        </div>
    </div>
</div>

@if ($sale)
    <div class="card mt-3">
        <div class="card-header">Found it</div>
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <p class="mb-1">
                    <strong>{{ $sale->invoice_number }}</strong>
                    &mdash; {{ \App\Support\BusinessDate::toLocal($sale->sale_time)->format('j M Y, g:ia') }}
                </p>
                <p class="small text-secondary mb-0">
                    {{ $sale->lines->count() }} {{ Str::plural('line', $sale->lines->count()) }},
                    paid <span class="money">{{ $sale->net_amount }}</span>
                    @if ($sale->is_returned)
                        &middot; <span class="text-warning">something has already been returned</span>
                    @endif
                </p>
            </div>
            <a href="{{ route('returns.create', $sale) }}" class="btn btn-primary">Choose what comes back</a>
        </div>
    </div>
@elseif ($sales->isNotEmpty())
    <div class="card mt-3">
        <div class="card-header">{{ $sales->count() }} recent {{ Str::plural('sale', $sales->count()) }}</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Invoice</th>
                        <th class="text-end">Paid</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sales as $past)
                        <tr>
                            <td class="small">
                                {{ \App\Support\BusinessDate::toLocal($past->sale_time)->format('j M y, g:ia') }}
                            </td>
                            <td>
                                {{ $past->invoice_number }}
                                @if ($past->is_returned)
                                    <span class="badge text-bg-warning">part returned</span>
                                @endif
                            </td>
                            <td class="money">{{ $past->net_amount }}</td>
                            <td class="text-end">
                                <a href="{{ route('returns.create', $past) }}" class="btn btn-sm btn-primary">
                                    Return against this
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@elseif ($searched)
    <div class="alert alert-warning mt-3">
        No sale found. Check the invoice number, or try the phone number instead — a
        walk-in sale has no customer attached and can only be found by its invoice.
    </div>
@endif

@endsection
