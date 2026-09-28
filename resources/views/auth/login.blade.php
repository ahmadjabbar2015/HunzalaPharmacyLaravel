{{--
    The login screen. Username and password, nothing else.

    No "forgot password" link: there is no email on file for most staff and no
    mail server on the box. A forgotten password is a thirty-second conversation
    with the owner, who can reset it in staff admin - which is both faster and
    harder to abuse than a reset link sent to an address nobody checks.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in &middot; {{ config('app.name') }}</title>
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body class="d-flex align-items-center py-5">

<main class="container" style="max-width: 26rem;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-1">{{ config('app.name') }}</h1>
            <p class="text-secondary small mb-4">Sign in to continue</p>

            @if ($errors->any())
                <div class="alert alert-danger py-2">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    {{--
                        autofocus and autocapitalize=off: the till is a shared
                        machine where this field is the first thing touched, and
                        a mobile keyboard would otherwise capitalise the name.
                    --}}
                    <input type="text" class="form-control" id="username" name="username"
                           value="{{ old('username') }}" required autofocus
                           autocomplete="username" autocapitalize="off" spellcheck="false">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password"
                           required autocomplete="current-password">
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember">
                    <label class="form-check-label small" for="remember">
                        Keep me signed in on this machine
                    </label>
                </div>

                <button type="submit" class="btn btn-primary w-100">Sign in</button>
            </form>

            <p class="text-secondary small mt-4 mb-0">
                Accounts are created by the shop owner. Ask them if you cannot get in.
            </p>
        </div>
    </div>
</main>

</body>
</html>
