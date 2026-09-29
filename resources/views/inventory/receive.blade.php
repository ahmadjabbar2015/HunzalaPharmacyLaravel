@extends('layouts.app')

@section('title', 'Receive '.$item->item_name)

@section('content')

<div class="row justify-content-center">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header">
                Receive stock &mdash; {{ $item->item_name }}
            </div>
            <div class="card-body">
                <p class="small text-secondary">
                    Every receipt lands in a batch with its own expiry date, because sales
                    draw from the batch expiring soonest. One delivery of two production
                    lots is two batches.
                </p>

                <form method="POST" action="{{ route('inventory.receive', $item) }}">
                    @csrf

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="batch_number" class="form-label">Batch number</label>
                            <input type="text" class="form-control @error('batch_number') is-invalid @enderror"
                                   id="batch_number" name="batch_number" value="{{ old('batch_number') }}"
                                   required autofocus maxlength="80" spellcheck="false">
                            @error('batch_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                From the box. Must be one this item does not already have — two
                                batches sharing a number cannot be told apart in a recall.
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="quantity" class="form-label">Quantity</label>
                            <input type="number" min="1" inputmode="numeric"
                                   class="form-control qty @error('quantity') is-invalid @enderror"
                                   id="quantity" name="quantity" value="{{ old('quantity') }}" required>
                            @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="purchase_price" class="form-label">Unit cost</label>
                            <input type="number" step="0.01" min="0" inputmode="decimal"
                                   class="form-control money @error('purchase_price') is-invalid @enderror"
                                   id="purchase_price" name="purchase_price"
                                   value="{{ old('purchase_price', $item->purchase_price) }}" required>
                            @error('purchase_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">What was paid for this lot.</div>
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="expiry_date" class="form-label">Expires</label>
                            <input type="date" class="form-control @error('expiry_date') is-invalid @enderror"
                                   id="expiry_date" name="expiry_date" value="{{ old('expiry_date') }}" required>
                            @error('expiry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                {{--
                                    Required, not optional: sales pick the batch expiring
                                    first, so a batch with no expiry could never be
                                    ordered and therefore never sold.
                                --}}
                                Required — it decides which batch sells first.
                            </div>
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="mfg_date" class="form-label">
                                Made <span class="text-secondary">(optional)</span>
                            </label>
                            <input type="date" class="form-control @error('mfg_date') is-invalid @enderror"
                                   id="mfg_date" name="mfg_date" value="{{ old('mfg_date') }}">
                            @error('mfg_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-success">Receive into stock</button>
                        <a href="{{ route('inventory.show', $item) }}" class="btn btn-link">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <p class="small text-secondary mt-3">
            This is a direct receipt against one item. To receive a whole delivery, or to
            reconcile what arrived against what was ordered, use
            <a href="{{ route('purchasing.orders.index') }}">purchase orders</a>.
        </p>
    </div>
</div>

@endsection
