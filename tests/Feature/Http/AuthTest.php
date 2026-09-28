<?php

/*
 * Login, logout, and who can reach what.
 *
 * No Python counterpart: the Flask client's equivalents live in
 * tests/test_web_security.py, but the routes and role names differ enough that
 * these are written against the Laravel surface rather than ported line by line.
 */

use App\Models\User;
use App\Services\PinService;

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'ahmed']);
    app(PinService::class)->setPassword($this->user, 'correct-horse');
    app(PinService::class)->setPin($this->user, '1234');
});

// ---------------------------------------------------------------------------
// login
// ---------------------------------------------------------------------------

it('shows the login form to a guest', function () {
    $this->get(route('login'))->assertOk()->assertSee('Sign in');
});

it('signs in with the right details', function () {
    $this->post(route('login.store'), ['username' => 'ahmed', 'password' => 'correct-horse'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->user);
});

it('refuses a wrong password', function () {
    $this->post(route('login.store'), ['username' => 'ahmed', 'password' => 'wrong'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('gives the same message for a wrong password and an unknown username', function () {
    // Two different messages would tell an attacker which usernames are real.
    // The exact wording is asserted on both, which pins it as well as proving
    // the two paths agree.
    $sameMessage = ['username' => 'Those details do not match an active account.'];

    $this->post(route('login.store'), ['username' => 'ahmed', 'password' => 'wrong'])
        ->assertSessionHasErrors($sameMessage);

    $this->post(route('login.store'), ['username' => 'nobody', 'password' => 'wrong'])
        ->assertSessionHasErrors($sameMessage);
});

it('refuses a deactivated account', function () {
    $this->user->forceFill(['is_active' => false])->save();

    $this->post(route('login.store'), ['username' => 'ahmed', 'password' => 'correct-horse'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('regenerates the session id on login', function () {
    // Otherwise a session fixed before authentication could be reused after it.
    $this->get(route('login'));
    $before = session()->getId();

    $this->post(route('login.store'), ['username' => 'ahmed', 'password' => 'correct-horse']);

    expect(session()->getId())->not->toBe($before);
});

it('throttles repeated failures', function () {
    foreach (range(1, 10) as $ignored) {
        $this->post(route('login.store'), ['username' => 'ahmed', 'password' => 'wrong']);
    }

    $response = $this->post(route('login.store'), ['username' => 'ahmed', 'password' => 'correct-horse']);

    // Even the CORRECT password is refused while throttled, or the throttle
    // would only slow down someone who never guesses right.
    $response->assertSessionHasErrors('username');
    expect($response->getSession()->get('errors')->first('username'))->toContain('Too many attempts');
    $this->assertGuest();
});

it('throttles per username rather than per address', function () {
    // The shop's staff share one IP. Throttling by address alone would let one
    // person's mistyped password lock the whole counter out mid-trade.
    $colleague = User::factory()->create(['username' => 'bilal']);
    app(PinService::class)->setPassword($colleague, 'other-password');

    foreach (range(1, 11) as $ignored) {
        $this->post(route('login.store'), ['username' => 'ahmed', 'password' => 'wrong']);
    }

    $this->post(route('login.store'), ['username' => 'bilal', 'password' => 'other-password'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($colleague);
});

// ---------------------------------------------------------------------------
// logout
// ---------------------------------------------------------------------------

it('logs out and invalidates the session', function () {
    $this->actingAs($this->user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    // The till's next user must not be able to resume the last one's session
    // with a back button.
    $this->assertGuest();
});

// ---------------------------------------------------------------------------
// guests reach nothing
// ---------------------------------------------------------------------------

it('sends a guest to the login screen', function () {
    foreach ([
        route('dashboard'),
        route('drawer.show'),
        route('profile.pin.edit'),
        route('staff.index'),
        route('settings.edit'),
    ] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

// ---------------------------------------------------------------------------
// role gates
// ---------------------------------------------------------------------------

it('keeps staff out of the owner screens', function () {
    // 403 from the route's Gate, before any screen renders. A screen that loads
    // and then refuses to work is worse than one that is plainly not yours.
    $this->actingAs($this->user)->get(route('staff.index'))->assertForbidden();
    $this->actingAs($this->user)->get(route('settings.edit'))->assertForbidden();
});

it('keeps staff and managers out of each other\'s business', function () {
    $manager = User::factory()->manager()->create(['username' => 'manager']);
    $owner = User::factory()->owner()->create(['username' => 'owner']);

    // A manager closes the drawer and reads reports, but does not manage accounts.
    expect($manager->can('close-drawer'))->toBeTrue()
        ->and($manager->can('view-reports'))->toBeTrue()
        ->and($manager->can('authorise-discount'))->toBeTrue()
        ->and($manager->can('manage-staff'))->toBeFalse()
        ->and($manager->can('manage-settings'))->toBeFalse();

    // Staff sell and receive stock, and open the drawer, but do not close it:
    // the person who might be short must not be the only one to sign it off.
    expect($this->user->can('sell'))->toBeTrue()
        ->and($this->user->can('open-drawer'))->toBeTrue()
        ->and($this->user->can('manage-inventory'))->toBeTrue()
        ->and($this->user->can('process-return'))->toBeTrue()
        ->and($this->user->can('close-drawer'))->toBeFalse()
        ->and($this->user->can('authorise-discount'))->toBeFalse()
        ->and($this->user->can('view-reports'))->toBeFalse();

    expect($owner->can('manage-staff'))->toBeTrue()
        ->and($owner->can('manage-settings'))->toBeTrue()
        ->and($owner->can('close-drawer'))->toBeTrue();
});

it('refuses everything to a deactivated account, whatever its role', function () {
    /*
     * A Gate::before, so it cannot be forgotten at one call site. The login
     * already refuses them, but a session already open when the owner
     * deactivates someone must stop working on the next request rather than at
     * the next login.
     */
    $owner = User::factory()->owner()->create(['username' => 'exowner', 'is_active' => false]);

    expect($owner->can('manage-staff'))->toBeFalse()
        ->and($owner->can('sell'))->toBeFalse()
        ->and($owner->can('close-drawer'))->toBeFalse();
});

it('lets an owner reach the owner screens', function () {
    $owner = User::factory()->owner()->create(['username' => 'owner']);

    $this->actingAs($owner)->get(route('staff.index'))->assertOk();
    $this->actingAs($owner)->get(route('settings.edit'))->assertOk();
});

// ---------------------------------------------------------------------------
// the screens render
// ---------------------------------------------------------------------------

it('renders the dashboard', function () {
    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertOk()
        // The drawer's state is on every screen: whether the till is open
        // decides whether a sale can be attributed to a session.
        ->assertSee('Drawer');
});

it('renders the drawer screen', function () {
    $this->actingAs($this->user)->get(route('drawer.show'))->assertOk()->assertSee('Cash drawer');
});

it('renders the PIN change screen for anybody', function () {
    $this->actingAs($this->user)->get(route('profile.pin.edit'))->assertOk()->assertSee('Change my PIN');
});
