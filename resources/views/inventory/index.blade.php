@extends('layouts.app')

@section('title', 'Inventory')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Inventory</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('inventory.alerts') }}" class="btn btn-outline-secondary">Alerts</a>
        <a href="{{ route('inventory.create') }}" class="btn btn-primary">Add an item</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('inventory.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label for="q" class="form-label small mb-1">Name, code or barcode</label>
                <input type="text" class="form-control form-control-sm" id="q" name="q"
                       value="{{ $filters['q'] ?? '' }}">
            </div>
            <div class="col-6 col-md-3">
                <label for="category" class="form-label small mb-1">Category</label>
                <select class="form-select form-select-sm" id="category" name="category">
                    <option value="">Any</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>
                            {{ $category }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="show" class="form-label small mb-1">Show</label>
                <select class="form-select form-select-sm" id="show" name="show">
                    <option value="all" @selected(($filters['show'] ?? 'all') === 'all')>Active</option>
                    <option value="low" @selected(($filters['show'] ?? '') === 'low')>Low stock</option>
                    <option value="out" @selected(($filters['show'] ?? '') === 'out')>Out of stock</option>
                    <option value="inactive" @selected(($filters['show'] ?? '') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

@if (in_array($filters['show'] ?? null, ['low', 'out'], true))
    {{--
        Said out loud rather than hidden, because a filter that quietly applies
        to one page is worse than no filter: whoever is reading it would draw a
        conclusion about the whole catalogue.
    --}}
    <p class="small text-secondary">
        Stock is derived from the ledger, so this filter applies to the items on
        this page. Use <a href="{{ route('inventory.alerts') }}">Alerts</a> for the
        whole catalogue.
    </p>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Where</th>
                    <th class="text-end">In stock (pieces)</th>
                    <th class="text-end">Reorder at</th>
                    <th class="text-end">Per piece</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php($item = $row['item'])
                    <tr>
                        <td>
                            <a href="{{ route('inventory.show', $item) }}" class="fw-semibold">
                                {{ $item->item_name }}
                            </a>
                            <span class="small text-secondary d-block">
                                {{ $item->item_code }}
                                @if ($item->is_narcotic)
                                    &middot; <span class="text-danger">controlled</span>
                                @endif
                                @unless ($item->is_active)
                                    &middot; inactive
                                @endunless
                            </span>
                        </td>
                        <td class="small">{{ $item->category ?? '—' }}</td>
                        <td class="small">{{ $item->location ?? '—' }}</td>
                        <td class="qty {{ $row['qty'] < 0 ? 'stock-negative' : '' }}">
                            {{ $row['qty'] }}
                            @if ($item->reorder_level > 0 && $row['qty'] <= $item->reorder_level && $row['qty'] >= 0)
                                <span class="badge text-bg-warning">low</span>
                            @endif
                            @if ($item->sells_in_packs && $row['qty'] > 0)
                                <span class="small text-secondary d-block">
                                    {{ intdiv($row['qty'], $item->pack_size) }} packs
                                    @if ($row['qty'] % $item->pack_size) + {{ $row['qty'] % $item->pack_size }} @endif
                                </span>
                            @endif
                        </td>
                        <td class="qty small text-secondary">
                            {{ $item->reorder_level > 0 ? $item->reorder_level : '—' }}
                        </td>
                        <td class="money">
                            {{ $item->piecePrice() }}
                            @if ($item->sells_in_packs)
                                {{-- The pack price too: it is what a supplier quote is in. --}}
                                <span class="small text-secondary d-block">
                                    {{ $item->packPrice() }} / {{ $item->pack_size }}
                                </span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('inventory.receive.form', $item) }}"
                               class="btn btn-sm btn-outline-success">Receive</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-secondary py-4 text-center">Nothing matches that.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $items->links() }}</div>

@endsection
