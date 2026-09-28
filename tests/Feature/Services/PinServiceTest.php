<?php

/*
 * Ported from tests/test_pin_service.py.
 *
 * The PIN is the shop's accountability backbone: it is the only thing that says
 * WHO rang a sale on a till nobody is logged into personally. These tests protect
 * the two properties that gives it - uniqueness, and a lockout that cannot be
 * brute-forced.
 */

use App\Exceptions\PinException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\PinService;

beforeEach(function () {
    $this->pins = app(PinService::class);
    $this->user = User::factory()->create(['username' => 'ahmed']);
    $this->pins->setPin($this->user, '1234');
});

// ---------------------------------------------------------------------------
// format
// ---------------------------------------------------------------------------

it('requires exactly four digits', function () {
    foreach (['123', '12345', 'abcd', '12a4', '', '12 4'] as $bad) {
        expect(fn () => $this->pins->setPin($this->user, $bad))
            ->toThrow(PinException::class);
    }
});

it('requires a password of at least four characters', function () {
    expect(fn () => $this->pins->setPassword($this->user, 'abc'))
        ->toThrow(PinException::class);
});

// ---------------------------------------------------------------------------
// uniqueness
// ---------------------------------------------------------------------------

it('refuses a PIN another active user already has', function () {
    // Two staff sharing a PIN makes every sale either of them rings ambiguous,
    // which defeats the only purpose the PIN has.
    $other = User::factory()->create(['username' => 'bilal']);

    expect(fn () => $this->pins->setPin($other, '1234'))->toThrow(PinException::class);
});

it('lets a user keep their own PIN', function () {
    // Re-submitting an unchanged form must not collide with itself.
    $this->pins->setPin($this->user, '1234');

    expect($this->pins->authenticateByPin('1234', DEVICE)->user->uuid)->toBe($this->user->uuid);
});

it('allows an inactive user\'s PIN to be reused', function () {
    // A departed member's four digits should not be retired from the shop
    // forever - there are only ten thousand of them.
    $gone = User::factory()->inactive()->create(['username' => 'departed']);
    $this->pins->setPin($gone, '9999', enforceUnique: false);

    $newHire = User::factory()->create(['username' => 'newhire']);
    $this->pins->setPin($newHire, '9999');

    expect($this->pins->authenticateByPin('9999', DEVICE)->user->uuid)->toBe($newHire->uuid);
});

// ---------------------------------------------------------------------------
// login by password
// ---------------------------------------------------------------------------

it('logs in with the right username and password', function () {
    $this->pins->setPassword($this->user, 'correct-horse');

    expect($this->pins->verifyLogin('ahmed', 'correct-horse')->uuid)->toBe($this->user->uuid);
});

it('refuses a wrong password or an unknown username', function () {
    $this->pins->setPassword($this->user, 'correct-horse');

    expect($this->pins->verifyLogin('ahmed', 'wrong'))->toBeNull()
        ->and($this->pins->verifyLogin('nobody', 'correct-horse'))->toBeNull();
});

it('refuses a deactivated account', function () {
    $this->pins->setPassword($this->user, 'correct-horse');
    $this->user->forceFill(['is_active' => false])->save();

    // Deactivation has to stop the login, or it stops nothing.
    expect($this->pins->verifyLogin('ahmed', 'correct-horse'))->toBeNull();
});

// ---------------------------------------------------------------------------
// the PIN check
// ---------------------------------------------------------------------------

it('accepts the right PIN for a known user', function () {
    $result = $this->pins->verifyPin($this->user, '1234', DEVICE);

    expect($result->ok)->toBeTrue()
        ->and($result->user->uuid)->toBe($this->user->uuid);
});

it('finds the user from the PIN alone', function () {
    // The pure-PIN pad: four digits and nothing else. PINs are unique among
    // active users, so the match is unambiguous.
    $result = $this->pins->authenticateByPin('1234', DEVICE);

    expect($result->ok)->toBeTrue()
        ->and($result->user->uuid)->toBe($this->user->uuid);
});

it('reports an unmatched PIN as unknown and locks nobody', function () {
    $result = $this->pins->authenticateByPin('0000', DEVICE);

    // Locking on a guessed PIN would be a denial of service against whoever
    // that PIN really belongs to.
    expect($result->ok)->toBeFalse()
        ->and($result->isUnknown)->toBeTrue()
        ->and($result->user)->toBeNull()
        ->and($this->user->fresh()->pin_failed_attempts)->toBe(0);
});

it('reports a badly formatted PIN as unknown rather than throwing', function () {
    // The pad sends whatever was typed; a stray keystroke is not an exception.
    expect($this->pins->authenticateByPin('12', DEVICE)->isUnknown)->toBeTrue();
});

it('counts down the remaining attempts on a wrong PIN', function () {
    $first = $this->pins->verifyPin($this->user, '9999', DEVICE);

    expect($first->ok)->toBeFalse()
        ->and($first->isLocked)->toBeFalse()
        ->and($first->remainingAttempts)->toBe(4)
        ->and($this->user->fresh()->pin_failed_attempts)->toBe(1);

    $second = $this->pins->verifyPin($this->user->fresh(), '9999', DEVICE);

    expect($second->remainingAttempts)->toBe(3);
});

it('resets the counter on a correct PIN', function () {
    // Five wrong tries spread over a week are a person misremembering, not an
    // attack, so the count must not accumulate across successful uses.
    $this->pins->verifyPin($this->user, '9999', DEVICE);
    $this->pins->verifyPin($this->user->fresh(), '9999', DEVICE);

    expect($this->user->fresh()->pin_failed_attempts)->toBe(2);

    $this->pins->verifyPin($this->user->fresh(), '1234', DEVICE);

    expect($this->user->fresh()->pin_failed_attempts)->toBe(0);
});

// ---------------------------------------------------------------------------
// lockout
// ---------------------------------------------------------------------------

it('locks the user after five wrong PINs', function () {
    $result = null;

    foreach (range(1, 5) as $ignored) {
        $result = $this->pins->verifyPin($this->user->fresh(), '9999', DEVICE);
    }

    expect($result->isLocked)->toBeTrue()
        ->and($result->remainingAttempts)->toBe(0)
        ->and($this->user->fresh()->isPinLocked())->toBeTrue()
        ->and($result->message)->toContain('15 min');
});

it('refuses even the correct PIN while locked', function () {
    foreach (range(1, 5) as $ignored) {
        $this->pins->verifyPin($this->user->fresh(), '9999', DEVICE);
    }

    // Otherwise the lock could be walked straight through by guessing right.
    $result = $this->pins->verifyPin($this->user->fresh(), '1234', DEVICE);

    expect($result->ok)->toBeFalse()
        ->and($result->isLocked)->toBeTrue();
});

it('audits the lockout', function () {
    foreach (range(1, 5) as $ignored) {
        $this->pins->verifyPin($this->user->fresh(), '9999', DEVICE);
    }

    $audit = AuditLog::where('action', 'pin_lockout')->where('user_uuid', $this->user->uuid)->first();

    expect($audit)->not->toBeNull()
        ->and($audit->details)->toContain('"attempts":5');
});

it('lets the lock expire on its own', function () {
    foreach (range(1, 5) as $ignored) {
        $this->pins->verifyPin($this->user->fresh(), '9999', DEVICE);
    }

    expect($this->user->fresh()->isPinLocked())->toBeTrue();

    // Fifteen minutes is long enough to deter guessing and short enough that a
    // shop is not left unable to trade.
    $this->travel(16)->minutes();

    expect($this->user->fresh()->isPinLocked())->toBeFalse()
        ->and($this->pins->verifyPin($this->user->fresh(), '1234', DEVICE)->ok)->toBeTrue();
});

it('unlocks on request', function () {
    foreach (range(1, 5) as $ignored) {
        $this->pins->verifyPin($this->user->fresh(), '9999', DEVICE);
    }

    $this->pins->unlock($this->user);

    expect($this->user->fresh()->isPinLocked())->toBeFalse()
        ->and($this->user->fresh()->pin_failed_attempts)->toBe(0);
});

it('clears a lock when a new PIN is set', function () {
    foreach (range(1, 5) as $ignored) {
        $this->pins->verifyPin($this->user->fresh(), '9999', DEVICE);
    }

    // The owner resetting the PIN IS the remedy for a locked-out member. Leaving
    // the lock would hand them a PIN they still cannot use for 15 minutes.
    $this->pins->setPin($this->user->fresh(), '5678');

    expect($this->user->fresh()->isPinLocked())->toBeFalse()
        ->and($this->pins->verifyPin($this->user->fresh(), '5678', DEVICE)->ok)->toBeTrue();
});

it('reports an inactive user as unknown', function () {
    $this->user->forceFill(['is_active' => false])->save();

    expect($this->pins->verifyPin($this->user, '1234', DEVICE)->isUnknown)->toBeTrue();
});

// ---------------------------------------------------------------------------
// changing and resetting
// ---------------------------------------------------------------------------

it('changes a PIN after proving the old one', function () {
    $this->pins->changePin($this->user, '1234', '5678', DEVICE);

    expect($this->pins->verifyPin($this->user->fresh(), '5678', DEVICE)->ok)->toBeTrue()
        ->and($this->pins->verifyPin($this->user->fresh(), '1234', DEVICE)->ok)->toBeFalse()
        ->and(AuditLog::where('action', 'pin_changed')->count())->toBe(1);
});

it('refuses a change with the wrong current PIN', function () {
    expect(fn () => $this->pins->changePin($this->user, '0000', '5678', DEVICE))
        ->toThrow(PinException::class);

    expect($this->pins->verifyPin($this->user->fresh(), '1234', DEVICE)->ok)->toBeTrue();
});

it('lets a manager reset someone else\'s PIN', function () {
    $manager = User::factory()->manager()->create(['username' => 'manager']);

    $this->pins->resetPin($manager, $this->user, '4321', DEVICE);

    $audit = AuditLog::where('action', 'pin_reset')->first();

    expect($this->pins->verifyPin($this->user->fresh(), '4321', DEVICE)->ok)->toBeTrue()
        // A reset hands one person the ability to ring sales as another, so the
        // trail has to name who did it.
        ->and($audit->details)->toContain($manager->uuid);
});

it('refuses a reset by a plain staff member', function () {
    $colleague = User::factory()->create(['username' => 'colleague']);

    expect(fn () => $this->pins->resetPin($colleague, $this->user, '4321', DEVICE))
        ->toThrow(PinException::class);
});

it('does not store the PIN in plain text', function () {
    // Obvious, and worth asserting: a readable pin_hash column would let anyone
    // with database access ring sales as anyone else.
    expect($this->user->fresh()->pin_hash)->not->toBe('1234')
        ->and($this->user->fresh()->pin_hash)->toStartWith('$');
});

it('keeps the lock counters out of the record hash', function () {
    /*
     * The counters are local security state. If a wrong PIN changed the user's
     * record hash, every mistyped PIN would look to a reconciler like someone
     * had edited the user row, and the row would drift against the server.
     */
    $before = $this->user->recordHash();

    $this->pins->verifyPin($this->user, '9999', DEVICE);

    expect($this->user->fresh()->pin_failed_attempts)->toBe(1)
        ->and($this->user->fresh()->recordHash())->toBe($before);
});
