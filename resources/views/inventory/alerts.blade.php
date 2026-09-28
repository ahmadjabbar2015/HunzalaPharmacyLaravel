@extends('layouts.app')

@section('title', 'Stock alerts')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Stock alerts</h1>
    <a href="{{ route('inventory.index') }}" class="btn btn-outline-secondary">All inventory</a>
</div>

{{--
    Four lists, one screen. They are all the same job: things somebody has to
    walk to a shelf and deal with. Split across four screens, three of them stop
    being looked at.
--}}

@if ($negative->isNotEmpty())
    <div class="card border-danger mb-3">
        <div class="card-header bg-danger text-white">
            Negative stock &mdash; {{ $negative->count() }}
            {{ Str::plural('item', $negative->count()) }}
        </div>
        <div class="card-body pb-0">
            <p class="small mb-3">
                The ledger says less than nothing is on the shelf, so the ledger and the
                shelf disagree. Only someone looking at the shelf can say which is right,
                which is why this is never corrected automatically.
            </p>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <tbody>
                    @foreach ($negative as $row)
                        <tr>
                            <td>
                                @if ($row['item'])
                                    <a href="{{ route('inventory.show', $row['item']) }}">
                                        {{ $row['item']->item_name }}
                                    </a>
                                    <span class="small text-secondary">{{ $row['item']->item_code }}</span>
                                @else
                                    <span class="text-secondary">{{ $row['item_uuid'] }}</span>
                                @endif
                            </td>
                            <td class="qty stock-negative">{{ $row['qty'] }}</td>
                            <td class="text-end">
                                @if ($row['item'])
                                    <a href="{{ route('inventory.adjust.form', $row['item']) }}"
                                       class="btn btn-sm btn-outline-danger">Count and adjust</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($expired->isNotEmpty())
    <div class="card border-danger mb-3">
        <div class="card-header">
            Expired, still in stock &mdash; {{ $expired->count() }}
            {{ Str::plural('batch', $expired->count()) }}
        </div>
        <div class="card-body pb-0">
            <p class="small mb-3">
                These cannot be sold. Pull them from the shelf and write them off with an
                adjustment, which records why the stock left.
            </p>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Batch</th>
                        <th>Expired</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($expired as $row)
                        <tr class="table-danger">
                            <td>
                                <a href="{{ route('inventory.show', $row['item']) }}">
                                    {{ $row['item']?->item_name }}
                                </a>
                            </td>
                            <td>{{ $row['batch']->batch_number }}</td>
                            <td>
                                {{ $row['batch']->expiry_date->format('j M Y') }}
                                <span class="small text-secondary">
                                    ({{ $row['batch']->expiry_date->diffForHumans() }})
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('inventory.adjust.form', $row['item']) }}"
                                   class="btn btn-sm btn-outline-danger">Write off</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                Expiring within {{ $expiryDays }} days &mdash; {{ $expiring->count() }}
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Batch</th>
                            <th>Expires</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Soonest first: whoever is clearing shelves works top-down. --}}
                        @forelse ($expiring as $row)
                            <tr>
                                <td>
                                    <a href="{{ route('inventory.show', $row['item']) }}">
                                        {{ $row['item']?->item_name }}
                                    </a>
                                </td>
                                <td class="small">{{ $row['batch']->batch_number }}</td>
                                <td class="small">{{ $row['batch']->expiry_date->format('j M Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-secondary py-3 text-center">
                                    Nothing expiring soon.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($expiring->isNotEmpty())
                <div class="card-footer small text-secondary">
                    Time enough to sell through or return to the supplier.
                </div>
            @endif
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                At or below reorder level &mdash; {{ $lowStock->count() }}
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-end">In stock</th>
                            <th class="text-end">Reorder at</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lowStock as $row)
                            <tr>
                                <td>
                                    <a href="{{ route('inventory.show', $row['item']) }}">
                                        {{ $row['item']->item_name }}
                                    </a>
                                </td>
                                <td class="qty">{{ $row['qty'] }}</td>
                                <td class="qty text-secondary">{{ $row['item']->reorder_level }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-secondary py-3 text-center">
                                    Nothing needs reordering.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($lowStock->isNotEmpty())
                <div class="card-footer small text-secondary">
                    Items with no reorder level set are not tracked here.
                </div>
            @endif
        </div>
    </div>
</div>

@endsection
