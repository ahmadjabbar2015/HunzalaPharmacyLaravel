@extends('layouts.app')

@section('title', 'Daily sales '.$report['date'])

@section('content')

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Daily sales</h1>
        <p class="text-secondary small mb-0">{{ $report['date'] }}</p>
    </div>

    <form method="GET" action="{{ route('reports.daily-sales') }}" class="d-flex gap-2 d-print-none">
        <input type="date" class="form-control form-control-sm" name="date" value="{{ $report['date'] }}">
        <button type="submit" class="btn btn-sm btn-outline-primary">Show</button>
        <a href="{{ route('reports.daily-sales.csv', ['date' => $report['date']]) }}"
           class="btn btn-sm btn-outline-secondary">CSV</a>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">Sales</div>
            <div class="h4 mb-0 qty">{{ $report['total_count'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">Takings</div>
            <div class="h4 mb-0 money">{{ $report['total_net'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">Discount given</div>
            <div class="h4 mb-0 money">{{ $report['total_discount'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        {{--
            Returns are shown alongside the takings, never subtracted from
            them. A quiet day and a day of heavy returns produce the same net
            figure, and the owner needs to be able to tell those apart.
        --}}
        <div class="card h-100"><div class="card-body">
            <div class="small text-secondary">Returns ({{ $report['return_count'] }})</div>
            <div class="h4 mb-0 money">{{ $report['return_total'] }}</div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
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
                            <tr><td colspan="3" class="text-secondary">Nothing sold.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">By payment method</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>How</th><th class="text-end">Sales</th><th class="text-end">Takings</th></tr></thead>
                    <tbody>
                        @forelse ($report['by_payment'] as $method => $totals)
                            <tr>
                                <td class="text-capitalize">{{ $method }}</td>
                                <td class="text-end qty">{{ $totals['count'] }}</td>
                                <td class="text-end money">{{ $totals['net'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-secondary">Nothing sold.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
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
                    <tr><td colspan="3" class="text-secondary">Nothing sold.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">Every sale</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Invoice</th><th>Time</th><th>Staff</th><th>Customer</th><th>Paid</th>
                    <th class="text-end">Subtotal</th><th class="text-end">Discount</th><th class="text-end">Net</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['sales'] as $sale)
                    <tr>
                        <td>
                            <a href="{{ route('sales.show', $sale['uuid']) }}">{{ $sale['invoice_number'] }}</a>
                            @if ($sale['is_returned'])
                                <span class="badge text-bg-warning">returned</span>
                            @endif
                        </td>
                        <td>{{ $sale['sale_time']?->format('H:i') }}</td>
                        <td>{{ $sale['staff_name'] }}</td>
                        <td>{{ $sale['customer_name'] }}</td>
                        <td class="text-capitalize">{{ $sale['payment_method'] }}</td>
                        <td class="text-end money">{{ $sale['subtotal'] }}</td>
                        <td class="text-end money">{{ $sale['discount'] }}</td>
                        <td class="text-end money">{{ $sale['net'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-secondary">No sales on this date.</td></tr>
                @endforelse
            </tbody>
            @if ($report['total_count'] > 0)
                <tfoot class="table-group-divider">
                    <tr class="fw-semibold">
                        <td colspan="5">Total</td>
                        <td class="text-end money">{{ $report['total_subtotal'] }}</td>
                        <td class="text-end money">{{ $report['total_discount'] }}</td>
                        <td class="text-end money">{{ $report['total_net'] }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

@endsection
