@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

    {{--
        What someone needs to know on walking in, in the order they need it:
        is the drawer open, what needs attention, and then the way to the till.
    --}}

    <div class="row g-3 mb-4">

        <div class="col-12 col-md-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 text-secondary">Drawer</h2>

                    @if ($drawer)
                        <p class="h4 mb-1 text-success">Open</p>
                        <p class="small text-secondary mb-2">
                            Since {{ \App\Support\BusinessDate::toLocal($drawer->opened_at)->format('j M, g:ia') }}
                            by {{ $drawer->openedBy?->full_name }}
                        </p>
                        <dl class="row small mb-0">
                            <dt class="col-7">Cash sales</dt>
                            <dd class="col-5 money">{{ $cashSales }}</dd>
                            <dt class="col-7">Expected cash</dt>
                            <dd class="col-5 money">{{ $expectedCash }}</dd>
                        </dl>
                    @else
                        <p class="h4 mb-1 text-warning">Closed</p>
                        <p class="small text-secondary mb-3">
                            Open it before the first sale, so takings are attributed to a session.
                        </p>
                        @can('open-drawer')
                            <a href="{{ route('drawer.show') }}" class="btn btn-sm btn-primary">Open the drawer</a>
                        @endcan
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 text-secondary">Today</h2>
                    <p class="h4 mb-1 money">{{ $todayTakings }}</p>
                    <p class="small text-secondary mb-0">
                        {{ $todaySaleCount }} {{ Str::plural('sale', $todaySaleCount) }}
                        on {{ \App\Support\BusinessDate::for()->format('j M Y') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 text-secondary">Low stock</h2>
                    <p class="h4 mb-1">{{ $lowStockCount }}</p>
                    <p class="small text-secondary mb-2">at or below reorder level</p>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 text-secondary">Expiry</h2>
                    <p class="h4 mb-1">
                        {{ $expiringCount }}
                        @if ($expiredCount > 0)
                            <span class="text-danger small">+{{ $expiredCount }} expired</span>
                        @endif
                    </p>
                    <p class="small text-secondary mb-2">
                        batches within {{ $expiryAlertDays }} days
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{--
        Negative stock gets its own banner rather than a tile. It means the
        ledger and the shelf disagree, and only a person looking at the shelf can
        say which is right - so it needs a name attached and a prompt to go and
        look, not a number.
    --}}
    @if ($negativeStock->isNotEmpty())
        <div class="alert alert-danger">
            <h2 class="h6">
                {{ $negativeStock->count() }}
                {{ Str::plural('item', $negativeStock->count()) }}
                {{ $negativeStock->count() === 1 ? 'shows' : 'show' }} negative stock
            </h2>
            <p class="small mb-2">
                The ledger says less than zero is on the shelf. Count the shelf, then
                adjust with a reason - this is never corrected automatically.
            </p>
            <ul class="small mb-0">
                @foreach ($negativeStock as $row)
                    <li>
                        {{ $row['item']?->item_name ?? $row['item_uuid'] }}
                        <span class="stock-negative">{{ $row['qty'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif


@endsection
