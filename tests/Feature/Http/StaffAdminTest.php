<?php

/*
 * Staff admin, the drawer screens, settings, and changing your own PIN.
 *
 * These test the HTTP layer specifically - that the right thing reaches the
 * service, and that a rule violation comes back as a message beside a field
 * rather than a 500. The rules themselves are proved in the service suites.
 */

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\PinService;
use App\Services\SessionService;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create(['username' => 'owner']);
    app(PinService::class)->setPin($this->owner, '1000');
});

// ---------------------------------------------------------------------------
// creating accounts
// ---------------------------------------------------------------------------

it('creates an account from the form', function () {
    $this->actingAs($this->owner)->post(route('staff.store'), [
        'username' => 'ahmed',
        'full_name' => 'Ahmed Ali',
        'phone' => '0300-1234567',
        'role' => 'staff',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'pin' => '1234',
    ])->assertRedirect(route('staff.index'));

    $created = User::where('username', 'ahmed')->first();

    expect($created)->not->toBeNull()
        ->and($created->role)->toBe('staff')
        // Both credentials work straight away - an account needing a second
        // setup step is one somebody leaves half-made.
        ->and(app(PinService::class)->verifyLogin('ahmed', 'password123'))->not->toBeNull()
        ->and(app(PinService::class)->authenticateByPin('1234', DEVICE)->ok)->toBeTrue();
});

it('returns a duplicate username to the form rather than failing', function () {
    User::factory()->create(['username' => 'ahmed']);

    $this->actingAs($this->owner)->post(route('staff.store'), [
        'username' => 'ahmed',
        'full_name' => 'Another Ahmed',
        'role' => 'staff',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'pin' => '1234',
    ])->assertSessionHasErrors('username');
});

it('returns an in-use PIN to the form', function () {
    $existing = User::factory()->create(['username' => 'bilal']);
    app(PinService::class)->setPin($existing, '1234');

    $this->actingAs($this->owner)->post(route('staff.store'), [
        'username' => 'ahmed',
        'full_name' => 'Ahmed Ali',
        'role' => 'staff',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'pin' => '1234',
    ])->assertSessionHasErrors('username');

    expect(User::where('username', 'ahmed')->exists())->toBeFalse();
});

it('validates the form before the service sees it', function () {
    $this->actingAs($this->owner)->post(route('staff.store'), [
        'username' => 'ahmed',
        'full_name' => 'Ahmed Ali',
        'role' => 'wizard',              // not a role
        'password' => 'short',           // under 8
        'password_confirmation' => 'different',
        'pin' => '12',                   // not 4 digits
    ])->assertSessionHasErrors(['role', 'password', 'pin']);
});

// ---------------------------------------------------------------------------
// editing and deactivating
// ---------------------------------------------------------------------------

it('edits an account', function () {
    $staff = User::factory()->create(['username' => 'ahmed', 'full_name' => 'Ahmed']);

    $this->actingAs($this->owner)->put(route('staff.update', $staff), [
        'full_name' => 'Ahmed Ali',
        'role' => 'manager',
    ])->assertRedirect(route('staff.index'));

    expect($staff->fresh()->full_name)->toBe('Ahmed Ali')
        ->and($staff->fresh()->role)->toBe('manager');
});

it('deactivates an account without deleting it', function () {
    $staff = User::factory()->create(['username' => 'ahmed']);

    $this->actingAs($this->owner)->delete(route('staff.deactivate', $staff))
        ->assertRedirect(route('staff.index'));

    expect($staff->fresh()->is_active)->toBeFalse()
        // The row survives, so the sales they rang still name them.
        ->and(User::find($staff->uuid))->not->toBeNull();
});

it('refuses to deactivate your own account, with a message', function () {
    $this->actingAs($this->owner)->delete(route('staff.deactivate', $this->owner))
        ->assertRedirect()
        ->assertSessionHas('error');

    // Locking yourself out of the only account that can create accounts is
    // unrecoverable from inside the application.
    expect($this->owner->fresh()->is_active)->toBeTrue();
});

it('reactivates an account', function () {
    $staff = User::factory()->inactive()->create(['username' => 'ahmed']);

    $this->actingAs($this->owner)->post(route('staff.reactivate', $staff))
        ->assertRedirect(route('staff.index'));

    expect($staff->fresh()->is_active)->toBeTrue();
});

// ---------------------------------------------------------------------------
// resetting a PIN
// ---------------------------------------------------------------------------

it('resets a PIN and clears any lockout', function () {
    $staff = User::factory()->create(['username' => 'ahmed']);
    app(PinService::class)->setPin($staff, '1234');

    // Lock them out first.
    foreach (range(1, 5) as $ignored) {
        app(PinService::class)->verifyPin($staff->fresh(), '9999', DEVICE);
    }

    expect($staff->fresh()->isPinLocked())->toBeTrue();

    $this->actingAs($this->owner)->put(route('staff.pin.reset', $staff), ['pin' => '4321'])
        ->assertRedirect(route('staff.index'));

    // The reset IS the remedy for a lockout, so leaving the lock in place would
    // hand them a PIN they still could not use.
    expect($staff->fresh()->isPinLocked())->toBeFalse()
        ->and(app(PinService::class)->verifyPin($staff->fresh(), '4321', DEVICE)->ok)->toBeTrue()
        ->and(AuditLog::where('action', 'pin_reset')->count())->toBe(1);
});

it('keeps staff out of a PIN reset', function () {
    $staff = User::factory()->create(['username' => 'ahmed']);

    $this->actingAs($staff)->put(route('staff.pin.reset', $this->owner), ['pin' => '4321'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// changing your own PIN
// ---------------------------------------------------------------------------

it('changes your own PIN', function () {
    $this->actingAs($this->owner)->put(route('profile.pin.update'), [
        'current_pin' => '1000',
        'new_pin' => '2000',
        'new_pin_confirmation' => '2000',
    ])->assertRedirect(route('dashboard'));

    expect(app(PinService::class)->verifyPin($this->owner->fresh(), '2000', DEVICE)->ok)->toBeTrue();
});

it('refuses your own PIN change without the current one', function () {
    $this->actingAs($this->owner)->put(route('profile.pin.update'), [
        'current_pin' => '0000',
        'new_pin' => '2000',
        'new_pin_confirmation' => '2000',
    ])->assertSessionHasErrors('current_pin');

    expect(app(PinService::class)->verifyPin($this->owner->fresh(), '1000', DEVICE)->ok)->toBeTrue();
});

it('requires the new PIN twice', function () {
    $this->actingAs($this->owner)->put(route('profile.pin.update'), [
        'current_pin' => '1000',
        'new_pin' => '2000',
        'new_pin_confirmation' => '9999',
    ])->assertSessionHasErrors('new_pin');
});

// ---------------------------------------------------------------------------
// the drawer
// ---------------------------------------------------------------------------

it('opens the drawer from the form', function () {
    $staff = User::factory()->create(['username' => 'ahmed']);

    $this->actingAs($staff)->post(route('drawer.open'), ['opening_float' => '500.00'])
        ->assertRedirect(route('drawer.show'))
        ->assertSessionHas('status');

    $drawer = app(SessionService::class)->openSession();

    // Staff open it: whoever arrives first does.
    expect($drawer)->not->toBeNull()
        ->and($drawer->opening_float)->toBe('500.00')
        ->and($drawer->opened_by_user_uuid)->toBe($staff->uuid);
});

it('records a blank opening float as not counted', function () {
    $this->actingAs($this->owner)->post(route('drawer.open'), ['opening_float' => '']);

    // Not the same as a counted zero: conflating them would make an uncounted
    // drawer look reconciled.
    expect(app(SessionService::class)->openSession()->opening_float)->toBeNull();
});

it('refuses a second open drawer with a message', function () {
    $this->actingAs($this->owner)->post(route('drawer.open'), ['opening_float' => '500']);

    $this->actingAs($this->owner)->post(route('drawer.open'), ['opening_float' => '100'])
        ->assertSessionHas('error');
});

it('keeps staff from closing the drawer', function () {
    $staff = User::factory()->create(['username' => 'ahmed']);
    $this->actingAs($staff)->post(route('drawer.open'), ['opening_float' => '500']);

    // The close computes the variance. The person who might be short must not be
    // the only one to record and explain it.
    $this->actingAs($staff)->post(route('drawer.close'), ['counted_cash' => '500'])
        ->assertForbidden();
});

it('closes the drawer and reports the variance in the message', function () {
    $this->actingAs($this->owner)->post(route('drawer.open'), ['opening_float' => '500']);

    $response = $this->actingAs($this->owner)->post(route('drawer.close'), ['counted_cash' => '450']);

    $response->assertRedirect(route('drawer.show'));

    // Reported back plainly, in the direction it went. A silent success would
    // leave whoever counted unsure the figure was even accepted.
    expect(session('status'))->toContain('SHORT')
        ->and(session('status'))->toContain('-50.00');
});

it('reports a balanced close as balanced', function () {
    $this->actingAs($this->owner)->post(route('drawer.open'), ['opening_float' => '500']);
    $this->actingAs($this->owner)->post(route('drawer.close'), ['counted_cash' => '500']);

    expect(session('status'))->toContain('balanced');
});

it('refuses to close when nothing is open', function () {
    $this->actingAs($this->owner)->post(route('drawer.close'), ['counted_cash' => '0'])
        ->assertSessionHas('error');
});

it('requires a counted figure to close', function () {
    $this->actingAs($this->owner)->post(route('drawer.open'), ['opening_float' => '500']);

    // Blank is not zero. Closing with no figure at all would record a variance
    // of minus the whole drawer.
    $this->actingAs($this->owner)->post(route('drawer.close'), [])
        ->assertSessionHasErrors('counted_cash');
});

// ---------------------------------------------------------------------------
// settings
// ---------------------------------------------------------------------------

it('creates the settings row on first view', function () {
    // Lazily rather than seeded, so a fresh deployment works whether or not
    // anyone remembered to run a seeder.
    expect(Setting::count())->toBe(0);

    $this->actingAs($this->owner)->get(route('settings.edit'))->assertOk();

    expect(Setting::count())->toBe(1);
});

it('saves settings', function () {
    $this->actingAs($this->owner)->get(route('settings.edit'));

    $this->actingAs($this->owner)->put(route('settings.update'), [
        'shop_name' => 'HunZala Pharmacy',
        'shop_phone' => '051-1234567',
        'discount_pin_threshold' => '250.00',
        'low_stock_threshold_default' => 15,
        'expiry_alert_days' => 45,
        'receipt_width' => '58mm',
    ])->assertRedirect(route('settings.edit'));

    $settings = Setting::first();

    expect($settings->shop_name)->toBe('HunZala Pharmacy')
        ->and($settings->discount_pin_threshold)->toBe('250.00')
        ->and($settings->expiry_alert_days)->toBe(45)
        ->and($settings->receipt_width)->toBe('58mm');
});

it('refuses a receipt width the printers cannot take', function () {
    $this->actingAs($this->owner)->get(route('settings.edit'));

    // 58mm and 80mm are the two thermal roll widths; anything else produces a
    // receipt that does not fit the paper.
    $this->actingAs($this->owner)->put(route('settings.update'), [
        'discount_pin_threshold' => '100',
        'low_stock_threshold_default' => 10,
        'expiry_alert_days' => 30,
        'receipt_width' => '110mm',
    ])->assertSessionHasErrors('receipt_width');
});

it('shows the shop name from settings in the layout', function () {
    $this->actingAs($this->owner)->get(route('settings.edit'));
    Setting::first()->update(['shop_name' => 'HunZala Medical Store']);

    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('HunZala Medical Store');
});
