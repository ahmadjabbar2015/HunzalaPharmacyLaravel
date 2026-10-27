@extends('layouts.app')

@section('title', 'Daily sale detail')

@section('content')

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 d-print-none">
    <div>
        <h1 class="h4 mb-0">Daily sale detail</h1>
        <p class="text-secondary small mb-0">
            Invoice by invoice, itemised - the print report.
        </p>
    </div>

    <form method="GET" action="{{ route('reports.daily-sale-detail') }}" class="d-flex gap-2">
        <input type="date" class="form-control form-control-sm" name="from" value="{{ $report['from'] }}">
        <input type="date" class="form-control form-control-sm" name="to" value="{{ $report['to'] }}">
        <button type="submit" class="btn btn-sm btn-outline-primary">Show</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button>
    </form>
</div>

{{--
    A print sheet, not a screen: this exists to be printed in the shape the
    shop's previous software produced it in, so it carries its own header
    rather than relying on the app chrome (which d-print-none hides anyway).
--}}
<div class="card">
    <div class="card-body">
        <div class="text-center mb-3">
            <h2 class="h5 mb-0">Daily Sale Detail</h2>
            <div class="fw-semibold">{{ $settings->shop_name ?? config('app.name') }}</div>
            @if ($settings?->shop_address)
                <div class="small text-secondary">{{ $settings->shop_address }}</div>
            @endif
            @if ($settings?->shop_phone)
                <div class="small text-secondary">{{ $settings->shop_phone }}</div>
            @endif
            <div class="small text-secondary mt-1">
                {{ \Carbon\CarbonImmutable::parse($report['from'])->format('d/m/Y') }}
                to
                {{ \Carbon\CarbonImmutable::parse($report['to'])->format('d/m/Y') }}
            </div>
        </div>

        @forelse ($report['sales'] as $sale)
            <div class="mb-3 pb-2 border-bottom">
                <div class="d-flex flex-wrap justify-content-between small text-secondary">
                    <div>
                        <strong class="text-body">Inv. #: {{ $sale['invoice_number'] }}</strong>
                        @if ($sale['is_returned'])
                            <span class="badge text-bg-warning">returned</span>
                        @endif
                        &middot; {{ $sale['sale_time']?->format('d/m/Y H:i') }}
                        &middot; {{ $sale['customer_name'] }}
                    </div>
                    <div>
                        {{ ucfirst($sale['payment_method']) }}
                        &middot; {{ $sale['staff_name'] }}
                        &middot; Machine: {{ $sale['device_id'] }}
                    </div>
                </div>

                <table class="table table-sm mb-1">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Item</th>
                            <th>Batch</th>
                            <th>Expiry</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sale['lines'] as $line)
                            <tr>
                                <td class="small text-secondary">{{ $line['item_code'] }}</td>
                                <td>{{ $line['item_name'] }}</td>
                                <td class="small text-secondary">{{ $line['batch_number'] }}</td>
                                <td class="small text-secondary">{{ $line['expiry_date']?->format('m/Y') }}</td>
                                <td class="text-end money">{{ $line['rate'] }}</td>
                                <td class="text-end qty">{{ $line['quantity'] }}</td>
                                <td class="text-end money">{{ $line['amount'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="d-flex justify-content-end gap-4 small">
                    @unless (\App\Support\Money::isZero($sale['discount_amount']))
                        <div>Discount: <span class="money">{{ $sale['discount_amount'] }}</span></div>
                    @endunless
                    <div class="fw-semibold">
                        Inv. Total: <span class="money">{{ $sale['net_amount'] }}</span>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-secondary text-center py-4">No sales in this range.</p>
        @endforelse

        @if ($report['total_count'] > 0)
            <div class="d-flex justify-content-between fw-bold border-top pt-2">
                <div>{{ number_format($report['total_count']) }} {{ Str::plural('invoice', $report['total_count']) }}</div>
                <div class="money">{{ $report['total_net'] }}</div>
            </div>
            @if ($report['return_count'] > 0)
                {{--
                    Shown beside the gross total, never netted off it - a
                    sale's own figures do not change once it is returned
                    against (see ReportService::dailySaleDetail).
                --}}
                <div class="d-flex justify-content-between text-warning-emphasis small">
                    <div>{{ number_format($report['return_count']) }} {{ Str::plural('return', $report['return_count']) }} given back</div>
                    <div class="money">{{ $report['return_total'] }}</div>
                </div>
                <div class="d-flex justify-content-between fw-bold">
                    <div>Net takings</div>
                    <div class="money">{{ $report['net_takings'] }}</div>
                </div>
            @endif
        @endif
    </div>
</div>

@endsection
