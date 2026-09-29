@extends('layouts.app')

@section('title', $supplier->supplier_name)

@section('content')

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">
            {{ $supplier->supplier_name }}
            @unless ($supplier->is_active)
                <span class="badge text-bg-secondary">dormant</span>
            @endunless
        </h1>
        <p class="text-secondary small mb-0">
            {{ $supplier->contact_person ?? 'no contact on file' }}
            @if ($supplier->phone) &middot; {{ $supplier->phone }} @endif
            @if ($supplier->payment_terms) &middot; {{ $supplier->payment_terms }} @endif
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('purchasing.orders.create', ['supplier' => $supplier->uuid]) }}"
           class="btn btn-primary">Place an order</a>
        <a href="{{ route('suppliers.edit', $supplier) }}" class="btn btn-outline-secondary">Edit</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-4">
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6 text-secondary">Outstanding</h2>
                <p class="display-6 mb-1 money {{ \App\Support\Money::isNegative($balance) ? 'text-success' : '' }}">
                    {{ $balance }}
                </p>
                <p class="small text-secondary mb-0">
                    Opening {{ $supplier->opening_balance }}, plus everything received,
                    less everything paid. Worked out from the rows each time.
                </p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Record a payment</div>
            <div class="card-body">
                <form method="POST" action="{{ route('suppliers.payments.store', $supplier) }}">
                    @csrf

                    <div class="row g-2">
                        <div class="col-6">
                            <label for="amount" class="form-label small mb-1">Amount</label>
                            <input type="number" step="0.01" inputmode="decimal"
                                   class="form-control money @error('amount') is-invalid @enderror"
                                   id="amount" name="amount" value="{{ old('amount') }}" required>
                            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-6">
                            <label for="payment_method" class="form-label small mb-1">By</label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                @foreach (config('pharmacy.supplier_payment_methods') as $method)
                                    <option value="{{ $method }}" @selected(old('payment_method') === $method)>
                                        {{ str_replace('_', ' ', ucfirst($method)) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-6">
                            <label for="payment_date" class="form-label small mb-1">Date</label>
                            <input type="date" class="form-control @error('payment_date') is-invalid @enderror"
                                   id="payment_date" name="payment_date"
                                   value="{{ old('payment_date', \App\Support\BusinessDate::today()) }}">
                            @error('payment_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-6">
                            <label for="reference" class="form-label small mb-1">Reference</label>
                            <input type="text" class="form-control" id="reference" name="reference"
                                   value="{{ old('reference') }}" maxlength="80" placeholder="cheque no.">
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label small mb-1">Notes</label>
                            <input type="text" class="form-control" id="notes" name="notes"
                                   value="{{ old('notes') }}" maxlength="200">
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-outline-primary w-100">Record the payment</button>
                        </div>
                    </div>
                </form>

                <p class="small text-secondary mt-3 mb-0">
                    {{--
                        Corrections are compensating entries, the same rule the
                        stock ledger follows. Nothing here edits an earlier row.
                    --}}
                    To correct an overpayment, record a negative amount. Payments are
                    never edited or deleted.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Payments</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        @forelse ($payments as $payment)
                            <tr>
                                <td class="small">{{ $payment->payment_date->format('j M y') }}</td>
                                <td class="small text-secondary">
                                    {{ str_replace('_', ' ', $payment->payment_method) }}
                                    @if ($payment->reference)
                                        <span class="d-block">{{ $payment->reference }}</span>
                                    @endif
                                </td>
                                <td class="money {{ \App\Support\Money::isNegative($payment->amount) ? 'text-danger' : '' }}">
                                    {{ $payment->amount }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-secondary small py-3 text-center">
                                    Nothing paid yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Orders</span>
                <a href="{{ route('purchasing.orders.index', ['supplier' => $supplier->uuid]) }}"
                   class="small">All orders</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Ordered</th>
                            <th>Number</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders->take(10) as $order)
                            <tr>
                                <td class="small">{{ $order->order_date->format('j M y') }}</td>
                                <td class="small">
                                    <a href="{{ route('purchasing.orders.show', $order) }}">{{ $order->po_number }}</a>
                                </td>
                                <td>
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
                                <td colspan="4" class="text-secondary small py-3 text-center">No orders yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Goods received</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Receipt</th>
                            <th>Invoice</th>
                            <th class="text-end">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($purchases->take(15) as $purchase)
                            <tr>
                                <td class="small">{{ $purchase->purchase_date->format('j M y') }}</td>
                                <td class="small">
                                    <a href="{{ route('purchasing.receipts.show', $purchase) }}">
                                        {{ $purchase->purchase_number }}
                                    </a>
                                </td>
                                <td class="small text-secondary">{{ $purchase->invoice_reference ?? '—' }}</td>
                                <td class="money">{{ $purchase->total_amount }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-secondary small py-3 text-center">
                                    Nothing received yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
