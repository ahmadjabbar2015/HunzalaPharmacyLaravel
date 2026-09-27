<?php

/*
 * Ported from tests/test_session_service.py.
 *
 * The drawer's lifecycle and its cash arithmetic. The variance these tests check
 * is the shop's main control against till shrinkage, so the arithmetic matters
 * more here than anywhere except the ledger itself.
 *
 * The Python suite's two close-blocking tests (unsynced pending, and the
 * owner/manager override) have no counterpart: that guard exists to stop a
 * drawer being counted while sales are stranded in an outbox on another device,
 * and LARAVEL_PLAN.md §8 drops the sync layer for a web-only shop. With one
 * server there is no outbox and nothing to strand. See SessionService's header.
 */

use App\Exceptions\SessionException;
use App\Models\AuditLog;
use App\Models\DrawerSession;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StaffPresence;
use App\Models\User;
use App\Services\PresenceService;
use App\Services\SessionService;
use App\Support\BusinessDate;

beforeEach(function () {
    $this->sessions = app(SessionService::class);
    $this->user = User::factory()->create(['username' => 'ahmed']);
});

/** A sale of $net against this drawer, paid by $method. */
function addSale(DrawerSession $drawer, User $user, string $net, string $method = 'cash'): Sale
{
    return Sale::create([
        'invoice_number' => 'T-'.fake()->unique()->numerify('####'),
        'sale_date' => BusinessDate::today(),
        'sale_time' => BusinessDate::nowUtc(),
        'staff_member_uuid' => $user->uuid,
        'session_uuid' => $drawer->uuid,
        'subtotal_amount' => $net,
        'net_amount' => $net,
        'payment_method' => $method,
        'origin_device_id' => DEVICE,
    ]);
}

// ---------------------------------------------------------------------------
// open
// ---------------------------------------------------------------------------

it('opens a session dated today', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);

    expect($drawer->status)->toBe('open')
        ->and($drawer->opening_float)->toBe('500.00')
        ->and($drawer->opened_by_user_uuid)->toBe($this->user->uuid)
        ->and($drawer->session_date->toDateString())->toBe(BusinessDate::today());
});

it('refuses a second open session', function () {
    $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);

    // Two open drawers would split one evening's takings across two variance
    // calculations, and neither would reconcile.
    expect(fn () => $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 100, deviceId: DEVICE))
        ->toThrow(SessionException::class);
});

it('allows a new session once the previous one is closed', function () {
    $first = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);
    $this->sessions->close(userUuid: $this->user->uuid, countedCash: 500, deviceId: DEVICE);

    $second = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 300, deviceId: DEVICE);

    expect($second->uuid)->not->toBe($first->uuid)
        ->and($this->sessions->openSession()->uuid)->toBe($second->uuid);
});

it('distinguishes an uncounted float from a counted zero', function () {
    // Both are legitimate. Conflating them would make an uncounted drawer look
    // reconciled against an opening float it never had.
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: null, deviceId: DEVICE);

    expect($drawer->opening_float)->toBeNull();

    // And expected cash still computes, treating the unknown float as zero.
    expect($this->sessions->expectedCash($drawer))->toBe('0.00');
});

it('audits the open', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);

    $audit = AuditLog::where('action', 'session_open')->where('session_uuid', $drawer->uuid)->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_uuid)->toBe($this->user->uuid)
        ->and($audit->details)->toContain('500.00');
});

// ---------------------------------------------------------------------------
// cash arithmetic - mobile never enters the drawer
// ---------------------------------------------------------------------------

it('counts cash sales and excludes mobile', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);

    addSale($drawer, $this->user, '300.00');
    addSale($drawer, $this->user, '200.00');
    addSale($drawer, $this->user, '1000.00', 'mobile');

    // 500 float + 500 cash. Counting the mobile takings would make every evening
    // look short by exactly that amount.
    expect($this->sessions->cashSalesTotal($drawer->uuid))->toBe('500.00')
        ->and($this->sessions->expectedCash($drawer))->toBe('1000.00');
});

it('subtracts cash refunds from expected cash', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);
    $sale = addSale($drawer, $this->user, '300.00');

    SaleReturn::create([
        'return_number' => 'T-R-0001',
        'original_sale_uuid' => $sale->uuid,
        'return_date' => BusinessDate::today(),
        'return_time' => BusinessDate::nowUtc(),
        'staff_member_uuid' => $this->user->uuid,
        'session_uuid' => $drawer->uuid,
        'total_amount' => '100.00',
        'refund_method' => 'cash',
        'origin_device_id' => DEVICE,
    ]);

    // Money handed back out of the drawer: 500 + 300 - 100.
    expect($this->sessions->cashRefundsTotal($drawer->uuid))->toBe('100.00')
        ->and($this->sessions->expectedCash($drawer))->toBe('700.00');
});

it('ignores a mobile refund in expected cash', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);
    $sale = addSale($drawer, $this->user, '300.00');

    SaleReturn::create([
        'return_number' => 'T-R-0002',
        'original_sale_uuid' => $sale->uuid,
        'return_date' => BusinessDate::today(),
        'return_time' => BusinessDate::nowUtc(),
        'staff_member_uuid' => $this->user->uuid,
        'session_uuid' => $drawer->uuid,
        'total_amount' => '100.00',
        'refund_method' => 'mobile',
        'origin_device_id' => DEVICE,
    ]);

    // Reversed on the phone, not out of the drawer, so the cash is untouched.
    expect($this->sessions->expectedCash($drawer))->toBe('800.00');
});

it('ignores another session\'s sales', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);
    addSale($drawer, $this->user, '300.00');
    $this->sessions->close(userUuid: $this->user->uuid, countedCash: 800, deviceId: DEVICE);

    $second = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 100, deviceId: DEVICE);
    addSale($second, $this->user, '50.00');

    // Yesterday's takings must not appear in tonight's expected cash.
    expect($this->sessions->expectedCash($second))->toBe('150.00');
});

it('keeps expected cash exact over many small sales', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: '0.00', deviceId: DEVICE);

    // 300 sales at 0.33. Accumulated as floats this drifts, and an unexplainable
    // variance of a few paisa is exactly what destroys trust in the figure.
    foreach (range(1, 300) as $ignored) {
        addSale($drawer, $this->user, '0.33');
    }

    expect($this->sessions->expectedCash($drawer))->toBe('99.00');
});

// ---------------------------------------------------------------------------
// close - variance is recorded, never blocked
// ---------------------------------------------------------------------------

it('computes and records a short drawer', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);
    addSale($drawer, $this->user, '300.00');
    addSale($drawer, $this->user, '200.00');
    addSale($drawer, $this->user, '1000.00', 'mobile');

    $closed = $this->sessions->close(userUuid: $this->user->uuid, countedCash: 950, deviceId: DEVICE);

    expect($closed->status)->toBe('closed')
        ->and($closed->expected_cash)->toBe('1000.00')
        ->and($closed->closing_cash_counted)->toBe('950.00')
        // Negative means SHORT. Signed, because which way it went is the first
        // question anyone asks.
        ->and($closed->cash_variance)->toBe('-50.00');
});

it('records an over drawer as a positive variance', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);

    $closed = $this->sessions->close(userUuid: $this->user->uuid, countedCash: 530, deviceId: DEVICE);

    expect($closed->cash_variance)->toBe('30.00');
});

it('closes a balanced drawer at zero variance', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);
    addSale($drawer, $this->user, '250.00');

    $closed = $this->sessions->close(userUuid: $this->user->uuid, countedCash: 750, deviceId: DEVICE);

    expect($closed->cash_variance)->toBe('0.00');
});

it('never blocks a close on variance', function () {
    // A short drawer is information the owner needs. Refusing to close until it
    // balances would simply teach staff to type the expected figure.
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);
    addSale($drawer, $this->user, '5000.00');

    $closed = $this->sessions->close(userUuid: $this->user->uuid, countedCash: 0, deviceId: DEVICE);

    expect($closed->status)->toBe('closed')
        ->and($closed->cash_variance)->toBe('-5500.00');
});

it('lets one person open and another close', function () {
    // The drawer is shared: whoever arrives first opens it, whoever leaves last
    // closes it. Tying a close to the opener would strand the drawer whenever
    // that person went home early.
    $opener = User::factory()->create(['username' => 'opener']);
    $closer = User::factory()->create(['username' => 'closer']);

    $drawer = $this->sessions->open(userUuid: $opener->uuid, openingFloat: 500, deviceId: DEVICE);
    $closed = $this->sessions->close(userUuid: $closer->uuid, countedCash: 500, deviceId: DEVICE);

    expect($closed->opened_by_user_uuid)->toBe($opener->uuid)
        ->and($closed->closed_by_user_uuid)->toBe($closer->uuid);
});

it('refuses to close when nothing is open', function () {
    expect(fn () => $this->sessions->close(userUuid: $this->user->uuid, countedCash: 0, deviceId: DEVICE))
        ->toThrow(SessionException::class);
});

it('audits the close with the counted, expected and variance figures', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);
    $this->sessions->close(userUuid: $this->user->uuid, countedCash: 450, deviceId: DEVICE);

    $audit = AuditLog::where('action', 'session_close')->where('session_uuid', $drawer->uuid)->first();

    expect($audit)->not->toBeNull()
        ->and($audit->details)->toContain('450.00')
        ->and($audit->details)->toContain('-50.00');
});

it('stores audit details with sorted keys', function () {
    // The record hash is computed over the stored text, so the same details in a
    // different key order would look to a reconciler like a tampered row.
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);
    $this->sessions->close(userUuid: $this->user->uuid, countedCash: 500, deviceId: DEVICE);

    $audit = AuditLog::where('action', 'session_close')->first();
    $keys = array_keys(json_decode($audit->details, true));

    expect($keys)->toBe(['counted', 'expected', 'variance']);
});

it('records the note taken at close', function () {
    $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);

    $closed = $this->sessions->close(
        userUuid: $this->user->uuid, countedCash: 450, deviceId: DEVICE,
        notes: 'paid 50 to the courier out of the drawer',
    );

    // A variance with an explanation attached is the difference between a note
    // and an investigation.
    expect($closed->notes)->toBe('paid 50 to the courier out of the drawer');
});

it('checks everyone out at close', function () {
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);
    $other = User::factory()->create(['username' => 'bilal']);

    app(PresenceService::class)->checkIn($drawer->uuid, $this->user->uuid, DEVICE);
    app(PresenceService::class)->checkIn($drawer->uuid, $other->uuid, DEVICE);

    $this->sessions->close(userUuid: $this->user->uuid, countedCash: 500, deviceId: DEVICE);

    // Nobody is on the floor of a closed shop.
    expect(StaffPresence::whereNull('checked_out_at')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// the business date, and sessions that cross midnight
// ---------------------------------------------------------------------------

it('dates a session by Karachi time, not UTC', function () {
    // 23:50 Karachi on the 1st is 18:50 UTC on the 1st.
    $this->travelTo('2026-09-01 18:50:00');
    expect(BusinessDate::today())->toBe('2026-09-01');

    // 00:15 Karachi on the 2nd is 19:15 UTC on the 1st - still the 1st in UTC,
    // but a new business day in the shop.
    $this->travelTo('2026-09-01 19:15:00');
    expect(BusinessDate::today())->toBe('2026-09-02');
});

it('keeps a session dated by its opening day after it closes past midnight', function () {
    // 23:30 Karachi on the 1st.
    $this->travelTo('2026-09-01 18:30:00');
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);

    expect($drawer->session_date->toDateString())->toBe('2026-09-01');

    // 00:45 Karachi on the 2nd - the same evening's trading.
    $this->travelTo('2026-09-01 19:45:00');
    $closed = $this->sessions->close(userUuid: $this->user->uuid, countedCash: 500, deviceId: DEVICE);

    // Re-dating it at close would move one evening's takings onto two days and
    // make both days' variance wrong.
    expect($closed->session_date->toDateString())->toBe('2026-09-01');
});

it('flags a session left open from an earlier day', function () {
    $this->travelTo('2026-09-01 10:00:00');
    $drawer = $this->sessions->open(userUuid: $this->user->uuid, openingFloat: 500, deviceId: DEVICE);

    // Nothing to flag on the day it was opened.
    expect($this->sessions->sessionNeedingClose())->toBeNull();

    $this->travelTo('2026-09-02 10:00:00');

    // Left open overnight it would quietly collect the next day's sales into
    // yesterday's takings, so the app prompts to close it.
    expect($this->sessions->sessionNeedingClose()?->uuid)->toBe($drawer->uuid);
});

it('flags nothing when there is no open session', function () {
    expect($this->sessions->sessionNeedingClose())->toBeNull()
        ->and($this->sessions->openSession())->toBeNull();
});
