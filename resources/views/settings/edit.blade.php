@extends('layouts.app')

@section('title', 'Settings')

@section('content')

<h1 class="h4 mb-3">Shop settings</h1>

<form method="POST" action="{{ route('settings.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header">The shop</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="shop_name" class="form-label">Shop name</label>
                        <input type="text" class="form-control" id="shop_name" name="shop_name"
                               value="{{ old('shop_name', $settings->shop_name) }}" maxlength="200">
                        <div class="form-text">Prints on every receipt.</div>
                    </div>

                    <div class="mb-3">
                        <label for="shop_phone" class="form-label">Phone</label>
                        <input type="text" class="form-control" id="shop_phone" name="shop_phone"
                               value="{{ old('shop_phone', $settings->shop_phone) }}" maxlength="40">
                    </div>

                    <div class="mb-0">
                        <label for="shop_address" class="form-label">Address</label>
                        <textarea class="form-control" id="shop_address" name="shop_address"
                                  rows="3">{{ old('shop_address', $settings->shop_address) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header">Thresholds</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="discount_pin_threshold" class="form-label">
                            Discount needing a manager's PIN
                        </label>
                        <input type="number" step="0.01" min="0" class="form-control money"
                               id="discount_pin_threshold" name="discount_pin_threshold"
                               value="{{ old('discount_pin_threshold', $settings->discount_pin_threshold) }}" required>
                        <div class="form-text">
                            Above this, a manager authorises. Below it, staff use their judgement —
                            a threshold set too low just teaches everyone to fetch a manager.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="low_stock_threshold_default" class="form-label">
                            Default reorder level for a new item
                        </label>
                        <input type="number" min="0" class="form-control"
                               id="low_stock_threshold_default" name="low_stock_threshold_default"
                               value="{{ old('low_stock_threshold_default', $settings->low_stock_threshold_default) }}" required>
                    </div>

                    <div class="mb-3">
                        <label for="expiry_alert_days" class="form-label">
                            Warn about expiry this many days ahead
                        </label>
                        <input type="number" min="1" class="form-control"
                               id="expiry_alert_days" name="expiry_alert_days"
                               value="{{ old('expiry_alert_days', $settings->expiry_alert_days) }}" required>
                        <div class="form-text">
                            Long enough to return stock to a supplier, short enough that the
                            list stays worth reading.
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-6 mb-0">
                            <label for="receipt_width" class="form-label">Receipt width</label>
                            {{-- The two thermal roll widths the shop's printers take. --}}
                            <select class="form-select" id="receipt_width" name="receipt_width" required>
                                @foreach (['58mm', '80mm'] as $width)
                                    <option value="{{ $width }}"
                                        @selected(old('receipt_width', $settings->receipt_width) === $width)>
                                        {{ $width }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-6 mb-0">
                            <label for="printer_name" class="form-label">Printer</label>
                            <input type="text" class="form-control" id="printer_name" name="printer_name"
                                   value="{{ old('printer_name', $settings->printer_name) }}" maxlength="120">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary mt-3">Save settings</button>
</form>

@endsection
