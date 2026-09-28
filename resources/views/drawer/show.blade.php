@extends('layouts.app')

@section('title', 'Cash drawer')

@section('content')

    <h1 class="h4 mb-3">Cash drawer</h1>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            @if ($drawer)
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            Open since
                            {{ \App\Support\BusinessDate::toLocal($drawer->opened_at)->format('j M Y, g:ia') }}
                        </span>
                        <span class="badge text-bg-success">Open</span>
                    </div>

                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-7">Opened by</dt>
                            <dd class="col-5">{{ $drawer->openedBy?->full_name ?? '—' }}</dd>

                            <dt class="col-7">Opening float</dt>
                            <dd class="col-5 money">
                                {{-- Null and zero are different answers, and the screen must say which. --}}
                                {{ $drawer->opening_float ?? 'not counted' }}
                            </dd>

                            <dt class="col-7">Cash sales</dt>
                            <dd class="col-5 money">{{ $cashSales }}</dd>

                            <dt class="col-7">Cash refunds</dt>
                            <dd class="col-5 money">−{{ $cashRefunds }}</dd>

                            <dt class="col-7 border-top pt-2 fw-semibold">Expected in the drawer</dt>
                            <dd class="col-5 border-top pt-2 fw-semibold money">{{ $expectedCash }}</dd>
                        </dl>

                        <p class="small text-secondary mt-3 mb-0">
                            Mobile payments are excluded: they never enter the drawer.
                        </p>
                    </div>
                </div>

                @can('close-drawer')
                    <div class="card">
                        <div class="card-header">Count and close</div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('drawer.close') }}">
                                @csrf

                                <div class="mb-3">
                                    <label for="counted_cash" class="form-label">
                                        Cash counted in the drawer
                                    </label>
                                    <input type="number" step="0.01" min="0" inputmode="decimal"
                                           class="form-control form-control-lg money @error('counted_cash') is-invalid @enderror"
                                           id="counted_cash" name="counted_cash"
                                           value="{{ old('counted_cash') }}" required autofocus>
                                    @error('counted_cash')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <div class="form-text">
                                        Count the notes and coins, not the expected figure. A difference is
                                        recorded, never blocked.
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="notes" class="form-label">
                                        Notes <span class="text-secondary">(optional)</span>
                                    </label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2"
                                              placeholder="e.g. paid 50 to the courier out of the drawer">{{ old('notes') }}</textarea>
                                    <div class="form-text">
                                        A difference with an explanation attached is a note. Without one it
                                        becomes an investigation.
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary">Close the drawer</button>
                            </form>
                        </div>
                    </div>
                @else
                    <div class="alert alert-secondary">
                        A manager or the owner closes the drawer. The person who counted it
                        is not the person who signs it off.
                    </div>
                @endcan

            @else
                <div class="card">
                    <div class="card-header">Open the drawer</div>
                    <div class="card-body">
                        <p class="text-secondary">
                            Open it before the first sale of the day. A sale rung with no open
                            drawer is still recorded, but it belongs to no session and cannot
                            be reconciled against a count.
                        </p>

                        @can('open-drawer')
                            <form method="POST" action="{{ route('drawer.open') }}">
                                @csrf

                                <div class="mb-3">
                                    <label for="opening_float" class="form-label">
                                        Opening float <span class="text-secondary">(leave blank if not counted)</span>
                                    </label>
                                    <input type="number" step="0.01" min="0" inputmode="decimal"
                                           class="form-control form-control-lg money @error('opening_float') is-invalid @enderror"
                                           id="opening_float" name="opening_float"
                                           value="{{ old('opening_float') }}" autofocus>
                                    @error('opening_float')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-3">
                                    <label for="notes" class="form-label">
                                        Notes <span class="text-secondary">(optional)</span>
                                    </label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2">{{ old('notes') }}</textarea>
                                </div>

                                <button type="submit" class="btn btn-primary">Open the drawer</button>
                            </form>
                        @endcan
                    </div>
                </div>
            @endif
        </div>

        <div class="col-12 col-lg-5">
            @if ($onFloor->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header">On the floor</div>
                    <ul class="list-group list-group-flush">
                        @foreach ($onFloor as $presence)
                            <li class="list-group-item d-flex justify-content-between">
                                <span>{{ $presence->user?->full_name ?? '—' }}</span>
                                <span class="small text-secondary">
                                    @if ($presence->checked_out_at)
                                        left {{ \App\Support\BusinessDate::toLocal($presence->checked_out_at)->format('g:ia') }}
                                    @else
                                        since {{ \App\Support\BusinessDate::toLocal($presence->checked_in_at)->format('g:ia') }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="card-footer small text-secondary">
                        Informational. Presence never blocks a sale, and selling checks
                        you in automatically.
                    </div>
                </div>
            @endif

            {{--
                Recent closes. A single short evening is noise; a run of them is
                the pattern the shop needs to see, and it should not require
                opening a report to notice.
            --}}
            <div class="card">
                <div class="card-header">Recent closes</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-end">Expected</th>
                                <th class="text-end">Counted</th>
                                <th class="text-end">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recent as $past)
                                <tr>
                                    <td>{{ $past->session_date->format('j M') }}</td>
                                    <td class="money">{{ $past->expected_cash }}</td>
                                    <td class="money">{{ $past->closing_cash_counted }}</td>
                                    <td class="money
                                        @if (\App\Support\Money::isNegative($past->cash_variance ?? 0)) variance-short
                                        @elseif (! \App\Support\Money::isZero($past->cash_variance ?? 0)) variance-over
                                        @endif">
                                        {{ $past->cash_variance }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-secondary">No closed sessions yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

@endsection
