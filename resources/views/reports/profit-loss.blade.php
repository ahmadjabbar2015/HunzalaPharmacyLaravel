@extends('layouts.app')

@section('title', 'Profit and loss')

@section('content')

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Profit &amp; loss</h1>
        <p class="text-secondary small mb-0">
            {{ $report['start_date'] }} to {{ $report['end_date'] }}
        </p>
    </div>

    <form method="GET" action="{{ route('reports.profit-loss') }}" class="d-flex flex-wrap gap-2 align-items-start d-print-none">
        <div>
            <input type="date" class="form-control form-control-sm @error('from') is-invalid @enderror"
                   name="from" value="{{ $report['start_date'] }}">
            @error('from')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div>
            <input type="date" class="form-control form-control-sm @error('to') is-invalid @enderror"
                   name="to" value="{{ $report['end_date'] }}">
            @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button type="submit" class="btn btn-sm btn-outline-primary">Show</button>
        <a href="{{ route('reports.profit-loss.csv', ['from' => $report['start_date'], 'to' => $report['end_date']]) }}"
           class="btn btn-sm btn-outline-secondary">CSV</a>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">Revenue</div>
            <div class="h4 mb-0 money">{{ $report['total_revenue'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">Cost of goods sold</div>
            <div class="h4 mb-0 money">{{ $report['total_cogs'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">Gross profit</div>
            <div class="h4 mb-0 money @if (\App\Support\Money::isNegative($report['total_gross_profit'])) variance-short @endif">
                {{ $report['total_gross_profit'] }}
            </div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">Net revenue</div>
            <div class="h4 mb-0 money">{{ $report['net_revenue'] }}</div>
            <div class="small text-secondary">
                after {{ $report['total_discount'] }} discount
                and {{ $report['total_returns'] }} returned
            </div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">Per medicine</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Code</th><th>Item</th>
                    <th class="text-end">Sold</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Gross profit</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['rows'] as $row)
                    <tr>
                        <td>{{ $row['item_code'] }}</td>
                        <td>{{ $row['item_name'] }}</td>
                        <td class="text-end qty">{{ $row['qty_sold'] }}</td>
                        <td class="text-end money">{{ $row['revenue'] }}</td>
                        <td class="text-end money">{{ $row['cogs'] }}</td>
                        <td class="text-end money @if (\App\Support\Money::isNegative($row['gross_profit'])) variance-short @endif">
                            {{ $row['gross_profit'] }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-secondary">Nothing was sold in this range.</td></tr>
                @endforelse
            </tbody>
            @if ($report['rows'] !== [])
                <tfoot class="table-group-divider">
                    <tr class="fw-semibold">
                        <td colspan="3">Total</td>
                        <td class="text-end money">{{ $report['total_revenue'] }}</td>
                        <td class="text-end money">{{ $report['total_cogs'] }}</td>
                        <td class="text-end money">{{ $report['total_gross_profit'] }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

<p class="small text-secondary mt-3">
    Cost is the price paid for the batch each sale actually drew from, not the
    item's current catalogue cost &mdash; those diverge the moment a supplier
    changes a price, and using today's figure would restate the profit on
    everything already sold. Returns reduce net revenue but are not credited
    back against cost: the stock returned to the shelf, so its cost belongs to
    whatever sells it next.
</p>

@endsection
