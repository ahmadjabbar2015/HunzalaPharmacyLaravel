<?php

namespace App\Http\Controllers;

use App\Exceptions\PinException;
use App\Exceptions\UserException;
use App\Models\User;
use App\Services\PinService;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/*
 * Staff accounts. Owner only, enforced by the route's Gate AND again by
 * UserService - the Gate decides what is reachable, the service decides what
 * actually happens.
 *
 * Accounts are deactivated, never deleted: every sale, adjustment and audit row
 * names a user, and deleting the row would orphan the shop's whole record of
 * who did what. The list therefore shows inactive accounts too, because
 * reactivating one is the normal way a returning member of staff is handled.
 */
class StaffController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly PinService $pins,
    ) {}

    public function index(): View
    {
        return view('staff.index', ['staff' => $this->users->list()]);
    }

    public function create(): View
    {
        return view('staff.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', 'alpha_dash'],
            'full_name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(config('pharmacy.user_roles'))],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'pin' => ['required', 'string', 'digits:'.config('pharmacy.pin.length')],
        ]);

        try {
            $this->users->create(
                actor: $request->user(),
                username: $validated['username'],
                fullName: $validated['full_name'],
                password: $validated['password'],
                pin: $validated['pin'],
                deviceId: config('pharmacy.device_id'),
                role: $validated['role'],
                phone: $validated['phone'] ?? null,
            );
        } catch (UserException $e) {
            // Back to the form with the message beside it. A duplicate username
            // or an in-use PIN is a correctable mistake, not a server error.
            return back()->withInput()->withErrors(['username' => $e->getMessage()]);
        }

        return redirect()->route('staff.index')
            ->with('status', "Account created for {$validated['full_name']}.");
    }

    public function edit(User $user): View
    {
        return view('staff.edit', ['staff' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(config('pharmacy.user_roles'))],
        ]);

        try {
            $this->users->update(
                actor: $request->user(),
                targetUuid: $user->uuid,
                deviceId: config('pharmacy.device_id'),
                changes: $validated,
            );
        } catch (UserException $e) {
            return back()->withInput()->withErrors(['role' => $e->getMessage()]);
        }

        return redirect()->route('staff.index')->with('status', 'Account updated.');
    }

    /**
     * An owner resets someone's PIN.
     *
     * Asks for no current PIN, because the whole point is that it has been
     * forgotten. Audited with the actor's uuid, since this hands one person the
     * ability to ring sales as another.
     */
    public function resetPin(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits:'.config('pharmacy.pin.length')],
        ]);

        try {
            $this->pins->resetPin(
                actor: $request->user(),
                target: $user,
                newPin: $validated['pin'],
                deviceId: config('pharmacy.device_id'),
            );
        } catch (PinException $e) {
            return back()->withErrors(['pin' => $e->getMessage()]);
        }

        return redirect()->route('staff.index')
            ->with('status', "PIN reset for {$user->full_name}. Any lockout has been cleared.");
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        try {
            $this->users->deactivate(
                actor: $request->user(),
                targetUuid: $user->uuid,
                deviceId: config('pharmacy.device_id'),
            );
        } catch (UserException $e) {
            // Deactivating yourself or the last owner lands here - both are
            // refusals the owner needs to read, not silent no-ops.
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('staff.index')
            ->with('status', "{$user->full_name} can no longer sign in. Their history is intact.");
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        try {
            $this->users->reactivate(
                actor: $request->user(),
                targetUuid: $user->uuid,
                deviceId: config('pharmacy.device_id'),
            );
        } catch (UserException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('staff.index')->with('status', "{$user->full_name} can sign in again.");
    }
}
