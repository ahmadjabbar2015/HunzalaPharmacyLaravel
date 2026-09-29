@extends('layouts.app')

@section('title', 'Drawer sessions')

@section('content')

<h1 class="h4 mb-1">Drawer sessions</h1>
<p class="text-secondary small mb-3">
    Newest first. A shortfall is shown in red &mdash; not as an accusation, but
    because a run of small ones in the same hand is the only way the pattern
    becomes visible at all.
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Date</th><th>Opened by</th><th>Closed by</th><th>Status</th>
                    <th class="text-end">Float</th>
                    <th class="text-end">Expected</th>
                    <th class="text-end">Counted</th>
                    <th class="text-end">Variance</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sessions as $session)
                    <tr>
                        <td>{{ $session->session_date?->format('j M Y') }}</td>
                        <td>{{ $session->openedBy?->full_name ?? '—' }}</td>
                        <td>{{ $session->closedBy?->full_name ?? '—' }}</td>
                        <td>
                            @if ($session->isOpen())
                                <span class="badge text-bg-success">open</span>
                            @else
                                <span class="badge text-bg-secondary">{{ $session->status }}</span>
                            @endif
                        </td>
                        <td class="text-end money">{{ \App\Support\Money::format($session->opening_float) }}</td>
                        <td class="text-end money">
                            {{ $session->expected_cash === null ? '—' : \App\Support\Money::format($session->expected_cash) }}
                        </td>
                        <td class="text-end money">
                            {{ $session->closing_cash_counted === null ? '—' : \App\Support\Money::format($session->closing_cash_counted) }}
                        </td>
                        <td class="text-end money @if ($session->cash_variance !== null && \App\Support\Money::isNegative($session->cash_variance)) variance-short @endif">
                            {{ $session->cash_variance === null ? '—' : \App\Support\Money::format($session->cash_variance) }}
                        </td>
                        <td class="text-end">
                            <a href="{{ route('reports.session', $session) }}" class="btn btn-sm btn-outline-primary">Summary</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-secondary">The drawer has never been opened.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $sessions->links() }}</div>

@endsection
