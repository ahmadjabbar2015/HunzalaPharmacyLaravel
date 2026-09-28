<?php

namespace App\Services;

use App\Models\User;

/*
 * The outcome of a PIN check, shaped for the pad that asked.
 *
 * Four distinct outcomes, deliberately not collapsed into a boolean, because the
 * pad must react differently to each:
 *
 *   ok       - attribute the sale to $user and carry on.
 *   locked   - inside a lockout window. The cart survives and a colleague can
 *              complete it with their own PIN.
 *   unknown  - no active user has this PIN. Nobody is locked, because there is
 *              no account to lock and locking a stranger's PIN would be a denial
 *              of service against whoever really owns it.
 *   wrong    - simply wrong; remainingAttempts tells the pad how many tries are
 *              left before a lock.
 *
 * Ported from pin_service.PinResult.
 */
readonly class PinResult
{
    private function __construct(
        public bool $ok,
        public ?User $user = null,
        public bool $isLocked = false,
        public bool $isUnknown = false,
        public int $remainingAttempts = 0,
        public string $message = '',
    ) {}

    public static function success(User $user): self
    {
        return new self(ok: true, user: $user, message: 'ok');
    }

    public static function locked(User $user, int $minutes): self
    {
        return new self(
            ok: false, user: $user, isLocked: true,
            message: "locked - try again in {$minutes} min",
        );
    }

    public static function unknown(string $message): self
    {
        return new self(ok: false, isUnknown: true, message: $message);
    }

    public static function wrong(User $user, int $remainingAttempts): self
    {
        return new self(
            ok: false, user: $user, remainingAttempts: $remainingAttempts,
            message: "wrong PIN - {$remainingAttempts} left",
        );
    }
}
