@extends('layouts.app')

@section('title', 'Inventory valuation')

@section('content')

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Inventory valuation</h1>
        <p class="text-secondary small mb-0">
            As at {{ \App\Support\BusinessDate::toLocal($report['generated_at'])->format('j M Y, H:i') }}.
            Valued at cost. Quantities are summed from the stock ledger, not the cached figures.
        </p>
    </div>

    <div class="d-flex gap-2 d-print-none">
        <a href="{{ route('reports.inventory.csv') }}" class="btn btn-sm btn-outline-secondary">CSV</a>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">Items stocked</div>
            <div class="h4 mb-0 qty">{{ $report['total_lines'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">Value at cost</div>
            <div class="h4 mb-0 money">{{ $report['total_value'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">Need reordering</div>
            <div class="h4 mb-0 qty">{{ $report['low_stock_count'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">
                Expiring within {{ $report['expiry_alert_days'] }} days
            </div>
            <div class="h4 mb-0 qty">{{ $report['expiring_count'] }}</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Code</th><th>Item</th><th>Manufacturer</th>
                    <th class="text-end">Stock</th><th class="text-end">Reorder at</th>
                    <th class="text-end">Cost</th><th class="text-end">Value</th>
                    <th>Batches</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['items'] as $item)
                    <tr>
                        <td><a href="{{ route('inventory.show', $item['uuid']) }}">{{ $item['item_code'] }}</a></td>
                        <td>{{ $item['item_name'] }}</td>
                        <td class="small text-secondary">{{ $item['manufacturer'] ?: '—' }}</td>
                        <td class="text-end qty @if ($item['derived_qty'] < 0) stock-negative @endif">
                            {{ $item['derived_qty'] }}
                            @if ($item['unit'])
                                <span class="small text-secondary">{{ $item['unit'] }}</span>
                            @endif
                        </td>
                        <td class="text-end qty">
                            {{ $item['reorder_level'] }}
                            @if ($item['is_low_stock'])
                                <span class="badge text-bg-warning">low</span>
                            @endif
                        </td>
                        <td class="text-end money">{{ $item['purchase_price'] }}</td>
                        <td class="text-end money">{{ $item['stock_value'] }}</td>
                        <td class="small">
                            {{--
                                Emptied batches are not listed. After a year of
                                trading there are hundreds of them per item and
                                none of them says anything about the shelf now.
                            --}}
                            @forelse ($item['batches'] as $batch)
                                <div>
                                    <span class="qty">{{ $batch['qty'] }}</span> &times;
                                    {{ $batch['batch_number'] }}
                                    @if ($batch['expiry_date'])
                                        <span @class([
                                            'text-danger fw-semibold' => $batch['is_expired'],
                                            'text-warning-emphasis' => $batch['is_expiring'] && ! $batch['is_expired'],
                                            'text-secondary' => ! $batch['is_expiring'],
                                        ])>
                                            exp {{ $batch['expiry_date']->format('M Y') }}
                                        </span>
                                    @endif
                                </div>
                            @empty
                                <span class="text-secondary">none in stock</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-secondary">No active items.</td></tr>
                @endforelse
            </tbody>
            @if ($report['total_lines'] > 0)
                <tfoot class="table-group-divider">
                    <tr class="fw-semibold">
                        <td colspan="6">Total at cost</td>
                        <td class="text-end money">{{ $report['total_value'] }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

@endsection
