@extends('layouts.app')

@section('title', 'Receive against '.$order->po_number)

@section('content')

<h1 class="h4 mb-1">Receive against {{ $order->po_number }}</h1>
<p class="text-secondary small mb-3">
    {{ $order->supplier?->supplier_name }} &middot; ordered {{ $order->order_date->format('j M Y') }}
</p>

@error('lines')
    <div class="alert alert-danger">{{ $message }}</div>
@enderror

<form method="POST" action="{{ route('purchasing.orders.receive', $order) }}">
    @csrf

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="invoice_reference" class="form-label">
                        Supplier invoice <span class="text-secondary">(optional)</span>
                    </label>
                    <input type="text" class="form-control" id="invoice_reference" name="invoice_reference"
                           value="{{ old('invoice_reference') }}" maxlength="80" autofocus>
                    <div class="form-text">From the delivery note, so the pair can be reconciled.</div>
                </div>

                <div class="col-6 col-md-3">
                    <label for="purchase_date" class="form-label">Received on</label>
                    <input type="date" class="form-control" id="purchase_date" name="purchase_date"
                           value="{{ old('purchase_date', \App\Support\BusinessDate::today()) }}">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">What arrived</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-end">Still due</th>
                        <th style="width: 7rem;" class="text-end">Arrived</th>
                        <th style="width: 9rem;">Batch</th>
                        <th style="width: 10rem;">Expires</th>
                        <th style="width: 8rem;" class="text-end">Unit cost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $i => $line)
                        @php($due = $line->quantity_ordered - $line->quantity_received)
                        <tr class="{{ $due <= 0 ? 'text-secondary' : '' }}">
                            <td>
                                {{ $line->item?->item_name ?? '—' }}
                                <span class="small text-secondary d-block">{{ $line->item?->item_code }}</span>
                                <input type="hidden" name="lines[{{ $i }}][po_item_uuid]" value="{{ $line->uuid }}">
                            </td>
                            <td class="qty">{{ $due > 0 ? $due : '—' }}</td>
                            <td>
                                <input type="number" min="0" max="{{ max($due, 0) }}" inputmode="numeric"
                                       class="form-control qty"
                                       name="lines[{{ $i }}][quantity]"
                                       value="{{ old("lines.{$i}.quantity", 0) }}"
                                       @disabled($due <= 0)>
                            </td>
                            <td>
                                <input type="text" class="form-control" spellcheck="false"
                                       name="lines[{{ $i }}][batch_number]"
                                       value="{{ old("lines.{$i}.batch_number") }}"
                                       @disabled($due <= 0)>
                            </td>
                            <td>
                                <input type="date" class="form-control"
                                       name="lines[{{ $i }}][expiry_date]"
                                       value="{{ old("lines.{$i}.expiry_date") }}"
                                       @disabled($due <= 0)>
                            </td>
                            <td>
                                {{--
                                    Prefilled from the order but editable: suppliers
                                    change a price between order and delivery, and the
                                    shop owes what was invoiced, not what was quoted.
                                --}}
                                <input type="number" step="0.01" min="0" inputmode="decimal"
                                       class="form-control money"
                                       name="lines[{{ $i }}][unit_cost]"
                                       value="{{ old("lines.{$i}.unit_cost", $line->unit_cost) }}"
                                       @disabled($due <= 0)>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-secondary">
            A line being received needs both a batch number and an expiry date &mdash;
            sales draw from the batch expiring soonest, so a batch without an expiry
            could never be sold. Leave a line at zero if it did not arrive.
        </div>
    </div>

    <button type="submit" class="btn btn-success btn-lg">Receive into stock</button>
    <a href="{{ route('purchasing.orders.show', $order) }}" class="btn btn-link">Cancel</a>
</form>

<p class="small text-secondary mt-3">
    Receiving more than is still due is refused. The order's status is worked out from
    what has actually arrived, so it can never claim a delivery that did not.
</p>

@endsection
