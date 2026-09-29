@extends('layouts.app')

@section('title', 'Place an order')

@section('content')

<h1 class="h4 mb-3">Place a purchase order</h1>

@error('lines')
    <div class="alert alert-danger">{{ $message }}</div>
@enderror

<form method="POST" action="{{ route('purchasing.orders.store') }}">
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
                            <option value="{{ $supplier->uuid }}"
                                @selected(old('supplier_uuid', request('supplier')) === $supplier->uuid)>
                                {{ $supplier->supplier_name }}
                            </option>
                        @endforeach
                    </select>
                    @error('supplier_uuid')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-6 col-md-3">
                    <label for="order_date" class="form-label">Order date</label>
                    <input type="date" class="form-control" id="order_date" name="order_date"
                           value="{{ old('order_date', \App\Support\BusinessDate::today()) }}">
                </div>

                <div class="col-12 col-md-4">
                    <label for="notes" class="form-label">
                        Notes <span class="text-secondary">(optional)</span>
                    </label>
                    <input type="text" class="form-control" id="notes" name="notes"
                           value="{{ old('notes') }}" maxlength="200">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">What to order</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style="width: 8rem;" class="text-end">Quantity</th>
                        <th style="width: 9rem;" class="text-end">Unit cost</th>
                    </tr>
                </thead>
                <tbody>
                    {{--
                        Eight fixed rows rather than a JavaScript row-adder. Chrome
                        109 has to run this, and a plain form that works is worth
                        more than a dynamic one that might not. Blank rows are
                        skipped on save.
                    --}}
                    @for ($i = 0; $i < 8; $i++)
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
                                       name="lines[{{ $i }}][quantity_ordered]"
                                       value="{{ old("lines.{$i}.quantity_ordered") }}">
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
            A unit cost of zero is allowed — a free replacement or a sample consignment
            is a real thing a supplier sends. Leave a row blank to skip it.
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg">Place the order</button>
    <a href="{{ route('purchasing.orders.index') }}" class="btn btn-link">Cancel</a>
</form>

@endsection
