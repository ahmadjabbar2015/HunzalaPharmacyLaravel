@extends('layouts.app')

@section('title', 'Session '.$report['session_date']?->format('j M Y'))

@section('content')

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Drawer session</h1>
        <p class="text-secondary small mb-0">
            {{ $report['session_date']?->format('l j F Y') }} &middot;
            <span class="text-capitalize">{{ $report['status'] }}</span>
        </p>
    </div>

    <div class="d-flex gap-2 d-print-none">
        <a href="{{ route('reports.session.csv', $report['session_uuid']) }}"
           class="btn btn-sm btn-outline-secondary">CSV</a>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button>
        <a href="{{ route('reports.sessions') }}" class="btn btn-sm btn-link">All sessions</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Cash reconciliation</div>
            <table class="table table-sm mb-0">
                <tbody>
                    <tr><th>Opening float</th><td class="text-end money">{{ $report['opening_float'] }}</td></tr>
                    <tr><th>Cash sales</th><td class="text-end money">{{ $report['cash_sales'] }}</td></tr>
                    <tr><th>Returns paid out</th><td class="text-end money">{{ $report['return_total'] }}</td></tr>
                    <tr class="table-group-divider">
                        <th>Expected in the drawer</th>
                        <td class="text-end money">{{ $report['expected_cash'] ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th>Counted</th>
                        <td class="text-end money">{{ $report['closing_cash_counted'] ?? '—' }}</td>
                    </tr>
                    <tr class="fw-semibold">
                        <th>Variance</th>
                        <td class="text-end money @if ($report['cash_variance'] !== null && \App\Support\Money::isNegative($report['cash_variance'])) variance-short @endif">
                            {{ $report['cash_variance'] ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
            @if ($report['cash_variance'] === null)
                {{--
                    An open drawer has no variance to show. Rendering 0.00 here
                    would read as "it balanced", which is the one thing it does
                    not yet mean.
                --}}
                <div class="card-footer small text-secondary">
                    This drawer has not been counted yet, so there is nothing to reconcile.
                </div>
            @endif
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">The shift</div>
            <table class="table table-sm mb-0">
                <tbody>
                    <tr><th>Opened by</th><td>{{ $report['opened_by'] }}</td></tr>
                    <tr><th>Opened at</th><td>{{ $report['opened_at']?->format('j M Y, H:i') }}</td></tr>
                    <tr><th>Closed by</th><td>{{ $report['closed_by'] ?? '—' }}</td></tr>
                    <tr><th>Closed at</th><td>{{ $report['closed_at']?->format('j M Y, H:i') ?? '—' }}</td></tr>
                    <tr class="table-group-divider"><th>Sales</th><td class="qty">{{ $report['sale_count'] }}</td></tr>
                    <tr><th>Cash</th><td class="money">{{ $report['cash_sales'] }}</td></tr>
                    <tr><th>Mobile</th><td class="money">{{ $report['mobile_sales'] }}</td></tr>
                    <tr class="fw-semibold"><th>Total takings</th><td class="money">{{ $report['total_net'] }}</td></tr>
                    <tr><th>Returns</th><td class="qty">{{ $report['return_count'] }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header">By staff</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Who</th><th class="text-end">Sales</th><th class="text-end">Takings</th></tr></thead>
                    <tbody>
                        @forelse ($report['by_staff'] as $name => $totals)
                            <tr>
                                <td>{{ $name }}</td>
                                <td class="text-end qty">{{ $totals['count'] }}</td>
                                <td class="text-end money">{{ $totals['net'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-secondary">Nothing sold in this session.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header">What was sold</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Medicine</th><th class="text-end">Quantity</th><th class="text-end">Value</th></tr></thead>
                    <tbody>
                        @forelse ($report['by_medicine'] as $name => $totals)
                            <tr>
                                <td>{{ $name }}</td>
                                <td class="text-end qty">{{ $totals['qty'] }}</td>
                                <td class="text-end money">{{ $totals['net'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-secondary">Nothing sold in this session.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
