<?php

/*
 * Ported from tests/test_user_service.py.
 *
 * Staff accounts are owner-only and never deleted. Every sale, adjustment and
 * audit row points at a user, so removing one would orphan the shop's record of
 * who did what.
 */

use App\Exceptions\UserException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\PinService;
use App\Services\UserService;

beforeEach(function () {
    $this->users = app(UserService::class);
    $this->pins = app(PinService::class);

    $this->owner = User::factory()->owner()->create(['username' => 'owner']);
});

/** Create a staff account with the boilerplate filled in. */
function makeStaff(User $owner, string $username, string $pin, string $role = 'staff'): User
{
    return app(UserService::class)->create(
        actor: $owner,
        username: $username,
        fullName: ucfirst($username).' Khan',
        password: 'password123',
        pin: $pin,
        deviceId: DEVICE,
        role: $role,
    );
}

// ---------------------------------------------------------------------------
// creating
// ---------------------------------------------------------------------------

it('creates a staff account', function () {
    $staff = makeStaff($this->owner, 'ahmed', '1111');

    expect($staff->username)->toBe('ahmed')
        ->and($staff->role)->toBe('staff')
        ->and($staff->is_active)->toBeTrue()
        // Both credentials are usable immediately - an account that needs a
        // second setup step is an account someone leaves half-made.
        ->and($this->pins->verifyLogin('ahmed', 'password123'))->not->toBeNull()
        ->and($this->pins->authenticateByPin('1111', DEVICE)->ok)->toBeTrue();
});

it('lets only an owner create accounts', function () {
    $manager = makeStaff($this->owner, 'manager', '2222', 'manager');
    $staff = makeStaff($this->owner, 'ahmed', '1111');

    // A manager can override a discount but not mint an account that can.
    expect(fn () => makeStaff($manager, 'newhire', '3333'))->toThrow(UserException::class);
    expect(fn () => makeStaff($staff, 'newhire', '3333'))->toThrow(UserException::class);
});

it('refuses a blank username or name', function () {
    expect(fn () => $this->users->create(
        actor: $this->owner, username: '  ', fullName: 'Someone',
        password: 'password123', pin: '1111', deviceId: DEVICE,
    ))->toThrow(UserException::class);

    expect(fn () => $this->users->create(
        actor: $this->owner, username: 'someone', fullName: '  ',
        password: 'password123', pin: '1111', deviceId: DEVICE,
    ))->toThrow(UserException::class);
});

it('refuses a duplicate username', function () {
    makeStaff($this->owner, 'ahmed', '1111');

    expect(fn () => makeStaff($this->owner, 'ahmed', '2222'))->toThrow(UserException::class);
});

it('refuses an unknown role', function () {
    expect(fn () => makeStaff($this->owner, 'ahmed', '1111', 'superuser'))
        ->toThrow(UserException::class);
});

it('surfaces a bad PIN or password as a user error', function () {
    // The caller is creating a user; that is the error it knows how to show
    // beside the form field.
    expect(fn () => $this->users->create(
        actor: $this->owner, username: 'ahmed', fullName: 'Ahmed',
        password: 'password123', pin: '12', deviceId: DEVICE,
    ))->toThrow(UserException::class);

    expect(fn () => $this->users->create(
        actor: $this->owner, username: 'ahmed', fullName: 'Ahmed',
        password: 'abc', pin: '1111', deviceId: DEVICE,
    ))->toThrow(UserException::class);
});

it('leaves no half-made account behind when a credential is rejected', function () {
    // The row and both credentials are one transaction. A user with no PIN could
    // not ring a sale, and nothing on the screen would say why.
    expect(fn () => $this->users->create(
        actor: $this->owner, username: 'ahmed', fullName: 'Ahmed',
        password: 'password123', pin: 'abcd', deviceId: DEVICE,
    ))->toThrow(UserException::class);

    expect(User::where('username', 'ahmed')->exists())->toBeFalse();
});

it('refuses a PIN another active user holds', function () {
    makeStaff($this->owner, 'ahmed', '1111');

    expect(fn () => makeStaff($this->owner, 'bilal', '1111'))->toThrow(UserException::class);
});

it('audits the creation with who did it', function () {
    $staff = makeStaff($this->owner, 'ahmed', '1111');

    $audit = AuditLog::where('action', 'user_created')->where('user_uuid', $staff->uuid)->first();

    expect($audit)->not->toBeNull()
        ->and($audit->details)->toContain($this->owner->uuid)
        ->and($audit->details)->toContain('staff');
});

// ---------------------------------------------------------------------------
// editing
// ---------------------------------------------------------------------------

it('edits a name, phone and role', function () {
    $staff = makeStaff($this->owner, 'ahmed', '1111');

    $this->users->update($this->owner, $staff->uuid, DEVICE, [
        'full_name' => 'Ahmed Ali',
        'phone' => '0300-1234567',
        'role' => 'manager',
    ]);

    expect($staff->fresh()->full_name)->toBe('Ahmed Ali')
        ->and($staff->fresh()->role)->toBe('manager');
});

it('refuses to edit the username', function () {
    // It is the login identity and it appears throughout the audit trail.
    $staff = makeStaff($this->owner, 'ahmed', '1111');

    expect(fn () => $this->users->update($this->owner, $staff->uuid, DEVICE, ['username' => 'someone-else']))
        ->toThrow(UserException::class);
});

it('refuses to set credentials through an edit', function () {
    // They go through PinService, which owns hashing, the format rules and the
    // audit row that goes with a change.
    $staff = makeStaff($this->owner, 'ahmed', '1111');

    expect(fn () => $this->users->update($this->owner, $staff->uuid, DEVICE, ['pin_hash' => 'anything']))
        ->toThrow(UserException::class);

    expect(fn () => $this->users->update($this->owner, $staff->uuid, DEVICE, ['password_hash' => 'anything']))
        ->toThrow(UserException::class);
});

it('refuses a blank name or unknown role on edit', function () {
    $staff = makeStaff($this->owner, 'ahmed', '1111');

    expect(fn () => $this->users->update($this->owner, $staff->uuid, DEVICE, ['full_name' => ' ']))
        ->toThrow(UserException::class);

    expect(fn () => $this->users->update($this->owner, $staff->uuid, DEVICE, ['role' => 'wizard']))
        ->toThrow(UserException::class);
});

it('audits a role change but not an ordinary edit', function () {
    $staff = makeStaff($this->owner, 'ahmed', '1111');

    $this->users->update($this->owner, $staff->uuid, DEVICE, ['phone' => '0300-0000000']);

    expect(AuditLog::where('action', 'user_role_changed')->count())->toBe(0);

    // A role change hands or removes the ability to manage accounts and override
    // discounts, so it is audited in its own right.
    $this->users->update($this->owner, $staff->uuid, DEVICE, ['role' => 'manager']);

    $audit = AuditLog::where('action', 'user_role_changed')->first();

    expect($audit->details)->toContain('"new_role":"manager"')
        ->and($audit->details)->toContain('"old_role":"staff"');
});

it('does not audit a role set to what it already was', function () {
    $staff = makeStaff($this->owner, 'ahmed', '1111');

    $this->users->update($this->owner, $staff->uuid, DEVICE, ['role' => 'staff']);

    expect(AuditLog::where('action', 'user_role_changed')->count())->toBe(0);
});

it('refuses to edit an unknown user', function () {
    expect(fn () => $this->users->update($this->owner, 'no-such-user', DEVICE, ['phone' => '1']))
        ->toThrow(UserException::class);
});

// ---------------------------------------------------------------------------
// deactivating
// ---------------------------------------------------------------------------

it('deactivates an account without deleting it', function () {
    $staff = makeStaff($this->owner, 'ahmed', '1111');

    $this->users->deactivate($this->owner, $staff->uuid, DEVICE);

    expect($staff->fresh()->is_active)->toBeFalse()
        // The row survives, so the sales they rang still name them.
        ->and(User::find($staff->uuid))->not->toBeNull()
        // And both credentials stop working.
        ->and($this->pins->verifyLogin('ahmed', 'password123'))->toBeNull()
        ->and($this->pins->authenticateByPin('1111', DEVICE)->ok)->toBeFalse();
});

it('refuses to deactivate your own account', function () {
    // Locking yourself out of the only account that can create accounts is
    // unrecoverable from inside the application.
    expect(fn () => $this->users->deactivate($this->owner, $this->owner->uuid, DEVICE))
        ->toThrow(UserException::class);
});

it('refuses to deactivate the last active owner', function () {
    $second = makeStaff($this->owner, 'second', '2222', 'owner');

    // With two owners, one may go.
    $this->users->deactivate($second, $this->owner->uuid, DEVICE);

    expect($this->owner->fresh()->is_active)->toBeFalse();

    // The remaining one may not - there would be no account left that could
    // create one.
    $staff = makeStaff($second, 'ahmed', '1111');

    expect(fn () => $this->users->deactivate($second, $second->uuid, DEVICE))
        ->toThrow(UserException::class);
});

it('refuses to demote the last active owner', function () {
    expect(fn () => $this->users->update($this->owner, $this->owner->uuid, DEVICE, ['role' => 'manager']))
        ->toThrow(UserException::class);
});

it('allows demoting an owner while another remains', function () {
    $second = makeStaff($this->owner, 'second', '2222', 'owner');

    $this->users->update($this->owner, $second->uuid, DEVICE, ['role' => 'manager']);

    expect($second->fresh()->role)->toBe('manager');
});

it('reactivates an account', function () {
    $staff = makeStaff($this->owner, 'ahmed', '1111');
    $this->users->deactivate($this->owner, $staff->uuid, DEVICE);

    $this->users->reactivate($this->owner, $staff->uuid, DEVICE);

    expect($staff->fresh()->is_active)->toBeTrue()
        ->and($this->pins->verifyLogin('ahmed', 'password123'))->not->toBeNull()
        ->and(AuditLog::where('action', 'user_reactivated')->count())->toBe(1);
});

it('lets only an owner deactivate or reactivate', function () {
    $manager = makeStaff($this->owner, 'manager', '2222', 'manager');
    $staff = makeStaff($this->owner, 'ahmed', '1111');

    expect(fn () => $this->users->deactivate($manager, $staff->uuid, DEVICE))
        ->toThrow(UserException::class);

    expect(fn () => $this->users->reactivate($manager, $staff->uuid, DEVICE))
        ->toThrow(UserException::class);
});

// ---------------------------------------------------------------------------
// listing
// ---------------------------------------------------------------------------

it('lists users by name, including inactive ones by default', function () {
    $staff = makeStaff($this->owner, 'ahmed', '1111');
    $gone = makeStaff($this->owner, 'departed', '2222');
    $this->users->deactivate($this->owner, $gone->uuid, DEVICE);

    // Staff admin needs to see a deactivated account in order to reactivate it.
    expect($this->users->list())->toHaveCount(3)
        ->and($this->users->list(activeOnly: true))->toHaveCount(2);
});
