{{--
    The shell every screen sits in.

    Kept deliberately plain. This is a working tool used for eight hours a day
    under fluorescent light, by people who need the same control to be in the
    same place every time - not a product to be admired.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'POS') &middot; {{ $shopName ?? config('app.name') }}</title>

    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body>

@auth
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-3 d-print-none">
        <div class="container-fluid">
            <a class="navbar-brand fw-semibold" href="{{ route('dashboard') }}">
                {{ $shopName ?? config('app.name') }}
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#mainNav" aria-controls="mainNav"
                    aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainNav">
                {{--
                    Nav entries are added as each screen lands (LARAVEL_PLAN.md
                    §6 steps 7-12). Sell will come first and stay first once the
                    POS exists: it is what the till is for, and muscle memory is
                    worth more than alphabetical order.
                --}}
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link @active('dashboard')" href="{{ route('dashboard') }}">Dashboard</a>
                    </li>
                </ul>

                <ul class="navbar-nav">
                    {{--
                        The drawer's state, always visible. Whether the till is
                        open decides whether a sale can be attributed to a
                        session, so it must never be something staff have to go
                        and check.
                    --}}
                    @if ($openDrawer ?? null)
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('drawer.show') }}">
                                <span class="badge text-bg-success">Drawer open</span>
                            </a>
                        </li>
                    @else
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('drawer.show') }}">
                                <span class="badge text-bg-warning">Drawer closed</span>
                            </a>
                        </li>
                    @endif

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            {{ auth()->user()->full_name }}
                            <span class="badge text-bg-light ms-1">{{ auth()->user()->role }}</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('profile.pin.edit') }}">Change my PIN</a></li>

                            @can('manage-staff')
                                <li><a class="dropdown-item" href="{{ route('staff.index') }}">Staff</a></li>
                            @endcan

                            @can('manage-settings')
                                <li><a class="dropdown-item" href="{{ route('settings.edit') }}">Settings</a></li>
                            @endcan

                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item">Log out</button>
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
@endauth

<main class="container-fluid pb-5">

    {{--
        An overnight drawer, surfaced on every screen until it is dealt with.

        Left open, it quietly collects the next day's sales into yesterday's
        takings and makes both days' variance wrong - so this is a banner rather
        than a line on a dashboard nobody opens.
    --}}
    @if ($staleDrawer ?? null)
        <div class="alert alert-warning d-flex justify-content-between align-items-center d-print-none">
            <span>
                The drawer from
                <strong>{{ $staleDrawer->session_date->format('j M Y') }}</strong>
                is still open. Close it before tonight's takings are mixed into it.
            </span>
            @can('close-drawer')
                <a href="{{ route('drawer.show') }}" class="btn btn-sm btn-warning">Close it</a>
            @endcan
        </div>
    @endif

    @if (session('status'))
        <div class="alert alert-success d-print-none">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger d-print-none">{{ session('error') }}</div>
    @endif

    @yield('content')
</main>

@stack('scripts')

</body>
</html>
