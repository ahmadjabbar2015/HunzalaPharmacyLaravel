<?php

namespace App\Http\Controllers;

use App\Exceptions\PinException;
use App\Services\PinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/*
 * A user changing their OWN PIN.
 *
 * Separate from StaffController's reset, and deliberately so. This one demands
 * the current PIN and is available to everybody; that one is owner/manager-only
 * and demands nothing, because it exists precisely for the case where the PIN
 * has been forgotten. Collapsing them into one screen would mean either staff
 * could not change their own PIN, or a manager had to know the old one.
 */
class ProfilePinController extends Controller
{
    public function __construct(private readonly PinService $pins) {}

    public function edit(): View
    {
        return view('profile.pin');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_pin' => ['required', 'string'],
            // digits:4 here as well as in PinService: the field should say "4
            // digits" beside itself rather than round-trip a service exception.
            'new_pin' => ['required', 'string', 'digits:'.config('pharmacy.pin.length'), 'confirmed'],
        ]);

        try {
            $this->pins->changePin(
                user: $request->user(),
                oldPin: $validated['current_pin'],
                newPin: $validated['new_pin'],
                deviceId: config('pharmacy.device_id'),
            );
        } catch (PinException $e) {
            return back()->withErrors(['current_pin' => $e->getMessage()]);
        }

        return redirect()->route('dashboard')->with('status', 'Your PIN has been changed.');
    }
}
