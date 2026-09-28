<?php

namespace App\Http\Controllers;

use App\Exceptions\SessionException;
use App\Models\DrawerSession;
use App\Services\PresenceService;
use App\Services\SessionService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/*
 * The cash drawer: open it, see where it stands, count it and close it.
 *
 * The close screen shows the expected figure BEFORE the count is entered, which
 * is a deliberate decision and an arguable one. Hiding it would make the count
 * blind and a shade more rigorous; showing it means whoever is closing can spot
 * a keying error while the drawer is still in front of them, rather than filing
 * a variance that takes an hour to chase. The variance is recorded either way,
 * and every close is audited with both figures.
 */
class DrawerController extends Controller
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly PresenceService $presence,
    ) {}

    public function show(): View
    {
        $drawer = $this->sessions->openSession();

        return view('drawer.show', [
            'drawer' => $drawer,
            'expectedCash' => $drawer ? $this->sessions->expectedCash($drawer) : Money::ZERO,
            'cashSales' => $drawer ? $this->sessions->cashSalesTotal($drawer->uuid) : Money::ZERO,
            'cashRefunds' => $drawer ? $this->sessions->cashRefundsTotal($drawer->uuid) : Money::ZERO,
            'onFloor' => $drawer ? $this->presence->forSession($drawer->uuid) : collect(),

            // The last few closes, so a pattern of shortfalls is visible without
            // opening a report. A single bad evening is noise; four in a row is
            // the thing the shop actually needs to see.
            'recent' => DrawerSession::query()
                ->where('status', 'closed')
                ->latest('closed_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Nullable, because "not counted" is a real answer and must not be
            // recorded as a counted zero.
            'opening_float' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->sessions->open(
                userUuid: $request->user()->uuid,
                openingFloat: $validated['opening_float'] ?? null,
                deviceId: config('pharmacy.device_id'),
                notes: $validated['notes'] ?? null,
            );
        } catch (SessionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('drawer.show')->with('status', 'The drawer is open.');
    }

    public function close(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $drawer = $this->sessions->close(
                userUuid: $request->user()->uuid,
                countedCash: $validated['counted_cash'],
                deviceId: config('pharmacy.device_id'),
                notes: $validated['notes'] ?? null,
            );
        } catch (SessionException $e) {
            return back()->with('error', $e->getMessage());
        }

        // The variance is reported back plainly, in the direction it went. A
        // close that silently succeeded would leave whoever counted unsure
        // whether the figure they typed was even accepted.
        $variance = $drawer->cash_variance;

        $message = Money::isZero($variance)
            ? 'Drawer closed and balanced exactly.'
            : (Money::isNegative($variance)
                ? "Drawer closed {$variance} SHORT against an expected {$drawer->expected_cash}."
                : "Drawer closed {$variance} OVER against an expected {$drawer->expected_cash}.");

        return redirect()->route('drawer.show')->with('status', $message);
    }
}
