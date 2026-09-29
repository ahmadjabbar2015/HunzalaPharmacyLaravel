@extends('layouts.app')

@section('title', 'Reports')

@section('content')

<h1 class="h4 mb-1">Reports</h1>
<p class="text-secondary small mb-4">
    Every one of these reads the records and changes nothing, so they are safe to
    run mid-trade. Each has a CSV download beside it for the accountant.
</p>

<div class="row g-3">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h6">Daily sales</h2>
                <p class="small text-secondary">
                    One day's takings, broken down by who served, how it was paid
                    and what was sold. This is the closing-time report.
                </p>
                <a href="{{ route('reports.daily-sales') }}" class="btn btn-sm btn-primary">
                    Today ({{ $today }})
                </a>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h6">Drawer sessions</h2>
                <p class="small text-secondary">
                    Every evening's float, takings, count and variance. The place
                    to look when the till was short and nobody knows when.
                </p>
                <a href="{{ route('reports.sessions') }}" class="btn btn-sm btn-primary">Session history</a>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h6">Inventory valuation</h2>
                <p class="small text-secondary">
                    What is on the shelves, batch by batch, valued at cost. Also
                    the file an expiry sweep or a recall is worked from.
                </p>
                <a href="{{ route('reports.inventory') }}" class="btn btn-sm btn-primary">Valuation</a>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h6">Profit &amp; loss</h2>
                <p class="small text-secondary">
                    Revenue less what the stock cost, per medicine, over any range
                    of dates. Costed from the batch each sale actually came from.
                </p>
                <a href="{{ route('reports.profit-loss') }}" class="btn btn-sm btn-primary">This month</a>
            </div>
        </div>
    </div>
</div>

@endsection
