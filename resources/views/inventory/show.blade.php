@extends('layouts.app')

@section('title', $item->item_name)

@section('content')

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">
            {{ $item->item_name }}
            @if ($item->is_narcotic)
                <span class="badge text-bg-danger">controlled</span>
            @endif
            @unless ($item->is_active)
                <span class="badge text-bg-secondary">inactive</span>
            @endunless
        </h1>
        <p class="text-secondary small mb-0">
            {{ $item->item_code }}
            @if ($item->barcode) &middot; barcode {{ $item->barcode }} @endif
            @if ($item->manufacturer) &middot; {{ $item->manufacturer }} @endif
        </p>
    </div>

    <div class="d-flex gap-2">
        <a href="{{ route('inventory.receive.form', $item) }}" class="btn btn-success">Receive stock</a>
        <a href="{{ route('inventory.adjust.form', $item) }}" class="btn btn-outline-warning">Adjust</a>
        <a href="{{ route('inventory.edit', $item) }}" class="btn btn-outline-secondary">Edit</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-4">
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6 text-secondary">In stock</h2>
                <p class="display-6 mb-1 {{ $derivedQty < 0 ? 'stock-negative' : '' }}">{{ $derivedQty }}</p>
                @if ($item->sells_in_packs)
                    {{-- Pieces is the number; packs is the shape of it on a shelf. --}}
                    <p class="small mb-1">{{ $item->describePieces(max(0, $derivedQty)) }}</p>
                @endif
                <p class="small text-secondary mb-0">
                    {{--
                        Said explicitly, because it is the single most important
                        fact about this number: it is summed from the movements
                        below, not stored anywhere.
                    --}}
                    Summed from the ledger below, not read from a column.
                </p>

                @if ($derivedQty < 0)
                    <div class="alert alert-danger small mt-3 mb-0">
                        The ledger says less than nothing is on the shelf. Count the shelf
                        and adjust with a reason — this is never corrected automatically.
                    </div>
                @elseif ($item->reorder_level > 0 && $derivedQty <= $item->reorder_level)
                    <div class="alert alert-warning small mt-3 mb-0">
                        At or below the reorder level of {{ $item->reorder_level }}.
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <dl class="row small mb-0">
                    {{--
                        Per piece first: it is what the till charges and what a
                        customer asking "how much for one?" is told. The pack
                        price is the second line, not the headline.
                    --}}
                    <dt class="col-6">Per {{ $item->unit_of_measure ?: 'piece' }}</dt>
                    <dd class="col-6 money">
                        {{ $item->piecePrice() }}
                        @if ($item->retail_price !== null && $item->sells_in_packs)
                            <span class="badge text-bg-light">set</span>
                        @endif
                    </dd>

                    <dt class="col-6">Pack of {{ $item->pack_size }}</dt>
                    <dd class="col-6 money">{{ $item->packPrice() }}</dd>

                    <dt class="col-6">Cost per pack</dt>
                    <dd class="col-6 money">{{ $item->purchase_price }}</dd>

                    <dt class="col-6">Max discount</dt>
                    <dd class="col-6">
                        @if ($item->max_discount_percent === null)
                            <span class="text-secondary">no limit</span>
                        @elseif ((float) $item->max_discount_percent === 0.0)
                            <span class="text-danger">never discount</span>
                        @else
                            {{ rtrim(rtrim($item->max_discount_percent, '0'), '.') }}%
                        @endif
                    </dd>

                    <dt class="col-6">Category</dt>
                    <dd class="col-6">{{ $item->category ?? '—' }}</dd>

                    <dt class="col-6">Shelf</dt>
                    <dd class="col-6">{{ $item->location ?? '—' }}</dd>

                    <dt class="col-6">Reorder at</dt>
                    <dd class="col-6">{{ $item->reorder_level > 0 ? $item->reorder_level : 'not tracked' }}</dd>
                </dl>

                @if ($item->description)
                    <hr>
                    <p class="small mb-0">{{ $item->description }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Batches</span>
                @if ($sellableBatch)
                    <span class="small text-secondary">
                        Next to sell: <strong>{{ $sellableBatch->batch_number }}</strong>
                        (expires {{ $sellableBatch->expiry_date->format('j M Y') }})
                    </span>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Batch</th>
                            <th>Expires</th>
                            <th class="text-end">Received</th>
                            <th class="text-end">Left</th>
                            <th class="text-end">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batches as $batch)
                            @php($left = $batchQuantities[$batch->uuid] ?? 0)
                            {{--
                                Ordered by expiry, which is the order they will be
                                sold in. Sorting by receipt date instead would hide
                                which lot goes next.
                            --}}
                            <tr class="{{ $batch->expiry_date->isPast() ? 'table-danger' : '' }}">
                                <td>
                                    {{ $batch->batch_number }}
                                    @if ($sellableBatch && $batch->uuid === $sellableBatch->uuid)
                                        <span class="badge text-bg-primary">next</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $batch->expiry_date->format('j M Y') }}
                                    @if ($batch->expiry_date->isPast())
                                        <span class="badge text-bg-danger">expired</span>
                                    @endif
                                </td>
                                <td class="qty text-secondary">{{ $batch->received_qty }}</td>
                                <td class="qty {{ $left < 0 ? 'stock-negative' : '' }}">{{ $left }}</td>
                                <td class="money">{{ $batch->purchase_price }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-secondary py-3 text-center">
                                    Nothing received yet. This item cannot be sold from stock until it is.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{--
            The ledger. This is the screen that answers "why does it say that?",
            which is the entire reason for keeping movements rather than a
            counter — so it belongs here, not buried in a report.
        --}}
        <div class="card">
            <div class="card-header">Every movement</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>What</th>
                            <th>Batch</th>
                            <th class="text-end">Change</th>
                            <th>Why</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ledger as $row)
                            <tr>
                                <td class="small">
                                    {{ \App\Support\BusinessDate::toLocal($row->transaction_date)->format('j M y, g:ia') }}
                                </td>
                                <td class="small">{{ $row->transaction_type }}</td>
                                <td class="small">{{ $row->batch?->batch_number ?? '—' }}</td>
                                <td class="qty {{ $row->qty_change < 0 ? 'text-danger' : 'text-success' }}">
                                    {{ $row->qty_change > 0 ? '+' : '' }}{{ $row->qty_change }}
                                </td>
                                <td class="small text-secondary">{{ $row->reason ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-secondary py-3 text-center">No movements yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($ledger->count() >= 50)
                <div class="card-footer small text-secondary">
                    Showing the 50 most recent movements.
                </div>
            @endif
        </div>
    </div>
</div>

@endsection
