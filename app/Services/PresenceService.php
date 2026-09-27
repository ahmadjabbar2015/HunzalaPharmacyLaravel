<?php

namespace App\Services;

use App\Models\StaffPresence;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/*
 * Who was on the shop floor during a drawer session.
 *
 * Answers "who was here when this happened" without being a timeclock. Staff
 * arrive and leave through the evening and the drawer is shared, so presence is
 * context for a variance investigation - not a permission and not attendance.
 *
 * Every method here is INFORMATIONAL and must never be able to block trading.
 * ensureCheckedIn() and autoCheckOutAll() swallow their own failures for that
 * reason: a presence bug must not stop a customer being served or a drawer being
 * counted.
 *
 * Ported from shared/services/presence_service.py.
 */
class PresenceService
{
    /** The open presence row for this user in this session, if any. */
    public function openPresence(string $sessionUuid, string $userUuid): ?StaffPresence
    {
        return StaffPresence::query()
            ->where('session_uuid', $sessionUuid)
            ->where('user_uuid', $userUuid)
            ->whereNull('checked_out_at')
            ->latest('checked_in_at')
            ->first();
    }

    public function isCheckedIn(string $sessionUuid, string $userUuid): bool
    {
        return $this->openPresence($sessionUuid, $userUuid) !== null;
    }

    /** Check a user in. Returns the existing row if they already are. */
    public function checkIn(string $sessionUuid, string $userUuid, string $deviceId): StaffPresence
    {
        $existing = $this->openPresence($sessionUuid, $userUuid);

        if ($existing !== null) {
            return $existing;
        }

        return StaffPresence::create([
            'session_uuid' => $sessionUuid,
            'user_uuid' => $userUuid,
            'checked_in_at' => BusinessDate::nowUtc(),
            'origin_device_id' => $deviceId,
        ]);
    }

    /** Check a user out. Returns null if they were not checked in. */
    public function checkOut(string $sessionUuid, string $userUuid): ?StaffPresence
    {
        $presence = $this->openPresence($sessionUuid, $userUuid);

        if ($presence === null) {
            return null;
        }

        $presence->checked_out_at = BusinessDate::nowUtc();
        $presence->save();

        return $presence;
    }

    /**
     * Silently check the user in if they are not already.
     *
     * A sale from someone not checked in checks them in rather than asking: the
     * staff member is demonstrably on the floor - they just served a customer -
     * so a prompt would be a question with one possible answer, asked while
     * someone waits at the counter.
     *
     * A no-op with no open session, since presence outside a session is
     * meaningless. Never throws: this must not be able to block a sale.
     */
    public function ensureCheckedIn(?string $sessionUuid, string $userUuid, string $deviceId): void
    {
        if ($sessionUuid === null || $sessionUuid === '') {
            return;
        }

        try {
            $this->checkIn($sessionUuid, $userUuid, $deviceId);
        } catch (Throwable $e) {
            Log::warning('auto check-in failed; the sale proceeds anyway', [
                'user' => $userUuid,
                'session' => $sessionUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check out everyone still on the floor when the drawer closes. Returns how
     * many rows were closed.
     *
     * Never throws: this must not be able to block a session close.
     */
    public function autoCheckOutAll(string $sessionUuid): int
    {
        try {
            $open = StaffPresence::query()
                ->where('session_uuid', $sessionUuid)
                ->whereNull('checked_out_at')
                ->get();

            $now = BusinessDate::nowUtc();

            foreach ($open as $presence) {
                $presence->checked_out_at = $now;
                $presence->save();
            }

            return $open->count();
        } catch (Throwable $e) {
            Log::warning('auto check-out failed; the close proceeds anyway', [
                'session' => $sessionUuid,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /** Everyone who was on the floor during a session, earliest first. */
    public function forSession(string $sessionUuid): Collection
    {
        return StaffPresence::query()
            ->where('session_uuid', $sessionUuid)
            ->orderBy('checked_in_at')
            ->get();
    }
}
