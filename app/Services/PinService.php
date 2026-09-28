<?php

namespace App\Services;

use App\Exceptions\PinException;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/*
 * Identity and the per-sale PIN - the accountability backbone.
 *
 * Two separate secrets per user, for two different jobs:
 *
 *   password - full login. Required for everything that is not ringing up a
 *              sale: reports, item management, settings, closing the drawer.
 *   PIN      - four digits entered at SAVE to attribute a sale. The screen at
 *              the till belongs to nobody; whoever completes the sale owns it.
 *
 * That split is the point. One shared login at the counter would make every sale
 * attributable to "the shop", and a full password typed per sale would be
 * shoulder-surfed within a day.
 *
 * Lockout: five wrong PINs in a row locks the user for fifteen minutes and
 * writes an audit row. The counters are LOCAL security state, excluded from the
 * record hash and never synced, so a mistyped PIN never drifts the user row
 * against the server.
 *
 * Ported from shared/services/pin_service.py.
 */
class PinService
{
    public function __construct(private readonly AuditService $audit) {}

    // -----------------------------------------------------------------------
    // credential setup
    // -----------------------------------------------------------------------

    /**
     * Hash and store a login password.
     *
     * @throws PinException if it is shorter than four characters
     */
    public function setPassword(User $user, string $password): void
    {
        if (mb_strlen($password) < 4) {
            throw new PinException('password must be at least 4 characters');
        }

        $user->password_hash = Hash::make($password);
        $user->save();
    }

    /**
     * Hash and store a PIN.
     *
     * Unique among ACTIVE users by default: a PIN identifies the person at save
     * time, and two staff sharing one would make every sale they ring
     * ambiguous - which defeats the only purpose the PIN has. Uniqueness is not
     * enforced against inactive users, because a departed member's PIN should be
     * reusable.
     *
     * @throws PinException on a bad format, or a PIN already in use
     */
    public function setPin(User $user, string $pin, bool $enforceUnique = true): void
    {
        $this->validateFormat($pin);

        if ($enforceUnique && $this->findActiveUserByPin($pin, excludeUuid: $user->uuid) !== null) {
            throw new PinException('that PIN is already in use by another user');
        }

        $user->pin_hash = Hash::make($pin);

        // A fresh PIN clears any lock: the owner resetting it IS the remedy for
        // a locked-out staff member, and leaving the lock in place would mean
        // handing someone a new PIN they still cannot use for 15 minutes.
        $user->pin_failed_attempts = 0;
        $user->pin_locked_until = null;
        $user->save();
    }

    /**
     * A user changes their own PIN, having proved the old one.
     *
     * @throws PinException if the current PIN is wrong, or the new one is invalid
     */
    public function changePin(User $user, string $oldPin, string $newPin, string $deviceId): void
    {
        if ($user->pin_hash === null || ! Hash::check($oldPin, $user->pin_hash)) {
            throw new PinException('current PIN is incorrect');
        }

        $this->setPin($user, $newPin);

        $this->audit->record(
            action: config('pharmacy.audit.pin_changed'),
            deviceId: $deviceId,
            userUuid: $user->uuid,
        );
    }

    /**
     * An owner or manager resets someone else's PIN.
     *
     * @throws PinException if the actor is a plain staff member
     */
    public function resetPin(User $actor, User $target, string $newPin, string $deviceId): void
    {
        if (! $actor->isManagerOrOwner()) {
            throw new PinException('only an owner or manager can reset a PIN');
        }

        $this->setPin($target, $newPin);

        // Logged with WHO reset it: a PIN reset hands one person the ability to
        // ring sales as another, so the trail has to name the actor.
        $this->audit->record(
            action: config('pharmacy.audit.pin_reset'),
            deviceId: $deviceId,
            userUuid: $target->uuid,
            details: ['reset_by' => $actor->uuid],
        );
    }

    // -----------------------------------------------------------------------
    // login
    // -----------------------------------------------------------------------

    /**
     * The active user for a correct username and password, or null.
     *
     * Always runs a hash check, even when the username does not exist, so the
     * response time does not reveal which usernames are real.
     */
    public function verifyLogin(string $username, string $password): ?User
    {
        $user = User::query()
            ->where('username', trim($username))
            ->where('is_active', true)
            ->first();

        $reference = $user?->password_hash ?? Hash::make('not-a-real-password');

        if (Hash::check($password, $reference) && $user !== null) {
            return $user;
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // the PIN check
    // -----------------------------------------------------------------------

    /**
     * Verify a known user's PIN and apply the lockout rules.
     *
     * The cart is never touched here. The caller decides what to do with the
     * result - a wrong PIN clears the pad and leaves the cart standing, so a
     * colleague can finish the sale with their own PIN rather than the customer
     * waiting while someone remembers four digits.
     */
    public function verifyPin(
        ?User $user,
        string $pin,
        string $deviceId,
        ?string $sessionUuid = null,
    ): PinResult {
        if ($user === null || ! $user->is_active) {
            return PinResult::unknown('unknown user');
        }

        if ($user->isPinLocked()) {
            return PinResult::locked($user, $this->lockMinutesRemaining($user));
        }

        if ($user->pin_hash !== null && Hash::check($pin, $user->pin_hash)) {
            // A correct PIN clears the counter: five wrong tries spread over a
            // week are a person misremembering, not an attack.
            if ($user->pin_failed_attempts > 0 || $user->pin_locked_until !== null) {
                $user->pin_failed_attempts = 0;
                $user->pin_locked_until = null;
                $user->save();
            }

            return PinResult::success($user);
        }

        $maxAttempts = config('pharmacy.pin.max_attempts');
        $lockoutMinutes = config('pharmacy.pin.lockout_minutes');

        $user->pin_failed_attempts = $user->pin_failed_attempts + 1;

        if ($user->pin_failed_attempts >= $maxAttempts) {
            $user->pin_locked_until = BusinessDate::nowUtc()->addMinutes($lockoutMinutes);
            $user->save();

            $this->audit->record(
                action: config('pharmacy.audit.pin_lockout'),
                deviceId: $deviceId,
                userUuid: $user->uuid,
                sessionUuid: $sessionUuid,
                details: [
                    'attempts' => $user->pin_failed_attempts,
                    'lockout_minutes' => $lockoutMinutes,
                ],
            );

            Log::warning('pin lockout', ['user' => $user->username, 'uuid' => $user->uuid]);

            return PinResult::locked($user, $lockoutMinutes);
        }

        $user->save();

        return PinResult::wrong($user, $maxAttempts - $user->pin_failed_attempts);
    }

    /**
     * Find the active user whose PIN this is, and verify it.
     *
     * The pure-PIN pad: the staff member types four digits and nothing else.
     * PINs are unique among active users, so a match is unambiguous. A PIN
     * matching nobody is reported unknown and locks no one - there is no account
     * to lock, and locking a guessed-at stranger would be a denial of service
     * against whoever that PIN really belongs to.
     */
    public function authenticateByPin(
        string $pin,
        string $deviceId,
        ?string $sessionUuid = null,
    ): PinResult {
        try {
            $this->validateFormat($pin);
        } catch (PinException) {
            return PinResult::unknown('bad PIN format');
        }

        $user = $this->findActiveUserByPin($pin);

        if ($user === null) {
            return PinResult::unknown('no matching user');
        }

        return $this->verifyPin($user, $pin, $deviceId, $sessionUuid);
    }

    /**
     * Clear a lockout immediately - an owner action, or a new PIN.
     *
     * Writes to the ROW rather than saving the instance, then refreshes it. A
     * caller holding a model loaded before the lock was applied has
     * pin_locked_until = null in memory already, so save() would find nothing
     * dirty and silently leave the user locked. These are hash-excluded columns,
     * so bypassing the model events costs nothing.
     */
    public function unlock(User $user): void
    {
        User::query()->whereKey($user->uuid)->update([
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ]);

        $user->refresh();
    }

    // -----------------------------------------------------------------------
    // internals
    // -----------------------------------------------------------------------

    /** @throws PinException if the PIN is not exactly the configured length, all digits */
    private function validateFormat(string $pin): void
    {
        $length = config('pharmacy.pin.length');

        if (mb_strlen($pin) !== $length || ! ctype_digit($pin)) {
            throw new PinException("PIN must be exactly {$length} digits");
        }
    }

    private function lockMinutesRemaining(User $user): int
    {
        if (! $user->isPinLocked()) {
            return 0;
        }

        // Rounded up, so "1 min" never means "any second now" to someone who
        // will try again immediately and be refused.
        return max(1, (int) ceil(BusinessDate::nowUtc()->diffInSeconds($user->pin_locked_until) / 60));
    }

    /**
     * The active user whose PIN matches, or null.
     *
     * Hashes are per-user salted, so there is no way to look a PIN up by index -
     * every candidate must be checked. Fine for a shop with two or three staff;
     * it would not be for two hundred.
     */
    private function findActiveUserByPin(string $pin, ?string $excludeUuid = null): ?User
    {
        $candidates = User::query()
            ->where('is_active', true)
            ->when($excludeUuid !== null, fn ($q) => $q->where('uuid', '!=', $excludeUuid))
            ->whereNotNull('pin_hash')
            ->get();

        foreach ($candidates as $user) {
            if (Hash::check($pin, $user->pin_hash)) {
                return $user;
            }
        }

        return null;
    }
}
