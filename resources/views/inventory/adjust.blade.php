@extends('layouts.app')

@section('title', 'Adjust '.$item->item_name)

@section('content')

<div class="row justify-content-center">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header">Adjust stock &mdash; {{ $item->item_name }}</div>
            <div class="card-body">
                <div class="alert alert-secondary">
                    <p class="mb-1">
                        The ledger currently says
                        <strong class="{{ $derivedQty < 0 ? 'stock-negative' : '' }}">{{ $derivedQty }}</strong>
                        on the shelf.
                    </p>
                    <p class="small mb-0">
                        {{--
                            Stated plainly because it is the property that makes the
                            ledger trustworthy: nothing here edits history.
                        --}}
                        An adjustment adds a new movement. It never edits or deletes an
                        earlier one, so the original record of what happened survives.
                    </p>
                </div>

                <form method="POST" action="{{ route('inventory.adjust', $item) }}">
                    @csrf

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="qty_change" class="form-label">Change by</label>
                            <input type="number" inputmode="numeric"
                                   class="form-control form-control-lg qty @error('qty_change') is-invalid @enderror"
                                   id="qty_change" name="qty_change" value="{{ old('qty_change') }}"
                                   required autofocus>
                            @error('qty_change')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                Negative to remove, positive to add. Not the new total &mdash; the
                                difference.
                            </div>
                        </div>

                        <div class="col-12 col-md-8">
                            <label for="batch_uuid" class="form-label">
                                Batch <span class="text-secondary">(optional)</span>
                            </label>
                            <select class="form-select" id="batch_uuid" name="batch_uuid">
                                <option value="">Not batch-specific</option>
                                @foreach ($batches as $batch)
                                    <option value="{{ $batch->uuid }}" @selected(old('batch_uuid') === $batch->uuid)>
                                        {{ $batch->batch_number }}
                                        &mdash; {{ $batchQuantities[$batch->uuid] ?? 0 }} left,
                                        expires {{ $batch->expiry_date->format('M Y') }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                Name the batch where you can. Expiry write-offs and damage
                                belong to a specific lot.
                            </div>
                        </div>

                        <div class="col-12">
                            <label for="reason" class="form-label">Reason</label>
                            <input type="text" class="form-control @error('reason') is-invalid @enderror"
                                   id="reason" name="reason" value="{{ old('reason') }}"
                                   required minlength="3" maxlength="500"
                                   placeholder="e.g. two strips crushed in transit; counted shelf, three short">
                            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                {{--
                                    The one movement with no document behind it, and the
                                    place shrinkage would hide. Hence mandatory.
                                --}}
                                Required. An adjustment is the only movement with no delivery
                                note or receipt behind it, so this is the record.
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-warning">Record the adjustment</button>
                        <a href="{{ route('inventory.show', $item) }}" class="btn btn-link">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
