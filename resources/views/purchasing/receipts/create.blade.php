@extends('layouts.app')

@section('title', 'Record a delivery')

@section('content')

<h1 class="h4 mb-1">Record a delivery</h1>
<p class="text-secondary small mb-3">
    For goods that arrived with no purchase order behind them &mdash; a walk-in
    delivery. There is simply nothing to reconcile against an order.
</p>

@error('lines')
    <div class="alert alert-danger">{{ $message }}</div>
@enderror

<form method="POST" action="{{ route('purchasing.receipts.store') }}">
    @csrf

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-5">
                    <label for="supplier_uuid" class="form-label">Supplier</label>
                    <select class="form-select @error('supplier_uuid') is-invalid @enderror"
                            id="supplier_uuid" name="supplier_uuid" required autofocus>
                        <option value="">Choose one</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->uuid }}" @selected(old('supplier_uuid') === $supplier->uuid)>
                                {{ $supplier->supplier_name }}
                            </option>
                        @endforeach
                    </select>
                    @error('supplier_uuid')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">The value is added to what the shop owes them.</div>
                </div>

                <div class="col-12 col-md-4">
                    <label for="invoice_reference" class="form-label">
                        Supplier invoice <span class="text-secondary">(optional)</span>
                    </label>
                    <input type="text" class="form-control" id="invoice_reference" name="invoice_reference"
                           value="{{ old('invoice_reference') }}" maxlength="80">
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
                        <th style="width: 7rem;" class="text-end">Quantity <span class="text-secondary fw-normal">(pieces)</span></th>
                        <th style="width: 9rem;">Batch</th>
                        <th style="width: 10rem;">Expires</th>
                        <th style="width: 8rem;" class="text-end">Cost per piece</th>
                    </tr>
                </thead>
                <tbody>
                    @for ($i = 0; $i < 6; $i++)
                        <tr>
                            <td>
                                <select class="form-select" name="lines[{{ $i }}][item_uuid]">
                                    <option value="">—</option>
                                    @foreach ($items as $item)
                                        <option value="{{ $item->uuid }}"
                                            @selected(old("lines.{$i}.item_uuid") === $item->uuid)>
                                            {{ $item->item_name }} ({{ $item->item_code }})
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input type="number" min="0" inputmode="numeric" class="form-control qty"
                                       name="lines[{{ $i }}][quantity]" value="{{ old("lines.{$i}.quantity") }}">
                            </td>
                            <td>
                                <input type="text" class="form-control" spellcheck="false"
                                       name="lines[{{ $i }}][batch_number]"
                                       value="{{ old("lines.{$i}.batch_number") }}">
                            </td>
                            <td>
                                <input type="date" class="form-control"
                                       name="lines[{{ $i }}][expiry_date]"
                                       value="{{ old("lines.{$i}.expiry_date") }}">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" inputmode="decimal"
                                       class="form-control money"
                                       name="lines[{{ $i }}][unit_cost]"
                                       value="{{ old("lines.{$i}.unit_cost") }}">
                            </td>
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-secondary">
            Every line needs a batch number and an expiry date. Leave a row blank to skip it.
        </div>
    </div>

    <button type="submit" class="btn btn-success btn-lg">Receive into stock</button>
    <a href="{{ route('purchasing.receipts.index') }}" class="btn btn-link">Cancel</a>
</form>

@endsection
