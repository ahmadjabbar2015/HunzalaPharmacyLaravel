<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/*
 * Username and password login.
 *
 * There is no registration screen and there never will be: accounts are created
 * by the owner in staff admin. A pharmacy has three staff, not three thousand,
 * and a public sign-up form would be a way in rather than a feature.
 *
 * Authentication goes through PinService::verifyLogin rather than
 * Auth::attempt(), because the credential column is password_hash and the
 * service already implements the timing-safe check for a username that does not
 * exist. Auth::login() is then used to establish the session.
 */
class LoginController extends Controller
{
    public function __construct(private readonly PinService $pins) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        /*
         * Throttled per username AND per IP. The shop's staff are on one IP, so
         * throttling by address alone would let one person's fat-fingered
         * password lock the whole counter out mid-trade.
         */
        $throttleKey = mb_strtolower($credentials['username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 10)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'username' => "Too many attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $user = $this->pins->verifyLogin($credentials['username'], $credentials['password']);

        if ($user === null) {
            RateLimiter::hit($throttleKey, decaySeconds: 60);

            // One message for a wrong password and for an unknown username: two
            // messages would tell an attacker which usernames are real.
            throw ValidationException::withMessages([
                'username' => 'Those details do not match an active account.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user, remember: $request->boolean('remember'));

        // A new session id on login, so a session fixed before authentication
        // cannot be reused after it.
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        // Invalidate and re-key, so the till's next user cannot resume the last
        // one's session with a back button.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
