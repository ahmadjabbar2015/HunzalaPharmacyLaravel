<?php

/*
 * The report screens and their CSV twins.
 *
 * Two things are being pinned here. The first is the gate: reports show what
 * every member of staff took and what the shop is worth, so a cashier must not
 * reach them at all. The second is that the CSV carries the same numbers as the
 * screen - a download that quietly disagreed with what the manager just read
 * would be worse than no download.
 */

use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\User;
use App\Services\CartLine;
use App\Services\SaleService;
use App\Services\SessionService;
use App\Services\StockService;
use App\Support\BusinessDate;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create(['username' => 'zala', 'full_name' => 'Zala Ahmad']);
    $this->manager = User::factory()->manager()->create(['username' => 'imran', 'full_name' => 'Imran Shah']);
    $this->cashier = User::factory()->create(['username' => 'ahmed', 'full_name' => 'Ahmed Ali']);

    $this->item = Item::factory()->create([
        'item_code' => 'PANADOL-500',
        'item_name' => 'Panadol 500mg',
        'purchase_price' => '8.00',
    ]);

    $this->batch = ItemBatch::factory()->for($this->item, 'item')->create([
        'batch_number' => 'B-1',
        'purchase_price' => '8.00',
    ]);

    app(StockService::class)->receive(
        itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 100, deviceId: DEVICE
    );

    $this->sale = app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 2, '12.00', $this->batch->uuid)],
        staffMemberUuid: $this->cashier->uuid,
        deviceId: DEVICE,
    );

    $this->today = BusinessDate::today();
});

// ---------------------------------------------------------------------------
// who may look
// ---------------------------------------------------------------------------

it('keeps a cashier out of every report', function (string $route) {
    $this->actingAs($this->cashier)->get($route)->assertForbidden();
})->with([
    fn () => route('reports.index'),
    fn () => route('reports.daily-sales'),
    fn () => route('reports.daily-sales.csv'),
    fn () => route('reports.sessions'),
    fn () => route('reports.inventory'),
    fn () => route('reports.inventory.csv'),
    fn () => route('reports.profit-loss'),
    fn () => route('reports.profit-loss.csv'),
]);

it('lets a manager in', function () {
    $this->actingAs($this->manager)->get(route('reports.index'))->assertOk();
});

it('offers reports in the nav to a manager but not to a cashier', function () {
    $this->actingAs($this->manager)->get(route('dashboard'))
        ->assertSee(route('reports.index'), escape: false);

    $this->actingAs($this->cashier)->get(route('dashboard'))
        ->assertDontSee(route('reports.index'), escape: false);
});

it('sends a guest to the login form', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// daily sales
// ---------------------------------------------------------------------------

it('shows today by default', function () {
    $this->actingAs($this->owner)->get(route('reports.daily-sales'))
        ->assertOk()
        ->assertSee($this->today)
        ->assertSee($this->sale->invoice_number)
        ->assertSee('Ahmed Ali')
        ->assertSee('24.00');
});

it('shows another date when asked', function () {
    $yesterday = BusinessDate::for()->subDay()->toDateString();

    $this->actingAs($this->owner)->get(route('reports.daily-sales', ['date' => $yesterday]))
        ->assertOk()
        ->assertSee($yesterday)
        ->assertDontSee($this->sale->invoice_number);
});

it('refuses a date that is not a date', function () {
    $this->actingAs($this->owner)
        ->get(route('reports.daily-sales', ['date' => 'last thursday']))
        ->assertSessionHasErrors('date');
});

it('downloads the day as a CSV carrying the same figures', function () {
    $response = $this->actingAs($this->owner)
        ->get(route('reports.daily-sales.csv', ['date' => $this->today]));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload("daily-sales-{$this->today}.csv");

    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('Invoice,Time,Staff')
        ->and($csv)->toContain($this->sale->invoice_number)
        ->and($csv)->toContain('Ahmed Ali')
        ->and($csv)->toContain('TOTAL');
});

// ---------------------------------------------------------------------------
// sessions
// ---------------------------------------------------------------------------

it('lists the drawer sessions', function () {
    app(SessionService::class)->open($this->owner->uuid, '1000.00', DEVICE);

    $this->actingAs($this->owner)->get(route('reports.sessions'))
        ->assertOk()
        ->assertSee('Zala Ahmad')
        ->assertSee('1000.00');
});

it('summarises one session, and exports it', function () {
    $sessions = app(SessionService::class);
    $drawer = $sessions->open($this->owner->uuid, '1000.00', DEVICE);

    app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 5, '12.00', $this->batch->uuid)],
        staffMemberUuid: $this->cashier->uuid,
        deviceId: DEVICE,
        sessionUuid: $drawer->uuid,
    );

    $sessions->close($this->owner->uuid, '1055.00', DEVICE, $drawer);

    $this->actingAs($this->owner)->get(route('reports.session', $drawer))
        ->assertOk()
        ->assertSee('1060.00')   // expected
        ->assertSee('1055.00')   // counted
        ->assertSee('-5.00');    // short by five

    $csv = $this->actingAs($this->owner)
        ->get(route('reports.session.csv', $drawer))
        ->assertOk()
        ->streamedContent();

    // The shortfall must survive as a number, not as text Excel refuses to
    // total - the whole reason Csv leaves numerics unescaped.
    expect($csv)->toContain('Variance,-5.00')
        ->and($csv)->toContain('Panadol 500mg');
});

it('404s an unknown session', function () {
    $this->actingAs($this->owner)->get('/reports/sessions/no-such-uuid')->assertNotFound();
});

// ---------------------------------------------------------------------------
// inventory valuation
// ---------------------------------------------------------------------------

it('values the shelves from the ledger, not the cache', function () {
    $this->item->forceFill(['current_stock_qty' => 99999])->save();

    // 98 left after the sale of 2, at 8.00 cost = 784.00.
    $this->actingAs($this->owner)->get(route('reports.inventory'))
        ->assertOk()
        ->assertSee('Panadol 500mg')
        ->assertSee('784.00')
        ->assertDontSee('99999');
});

it('exports the valuation one row per batch', function () {
    $csv = $this->actingAs($this->owner)
        ->get(route('reports.inventory.csv'))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Code,Item,Manufacturer')
        ->and($csv)->toContain('PANADOL-500')
        ->and($csv)->toContain('B-1')
        ->and($csv)->toContain('784.00');
});

// ---------------------------------------------------------------------------
// profit & loss
// ---------------------------------------------------------------------------

it('defaults the profit and loss to the month so far', function () {
    $this->actingAs($this->owner)->get(route('reports.profit-loss'))
        ->assertOk()
        ->assertSee(BusinessDate::for()->startOfMonth()->toDateString())
        ->assertSee($this->today)
        // 2 × 12.00 revenue, 2 × 8.00 cost, 8.00 gross.
        ->assertSee('24.00')
        ->assertSee('16.00')
        ->assertSee('8.00');
});

it('refuses a range that ends before it starts, next to the field', function () {
    $this->actingAs($this->owner)
        ->get(route('reports.profit-loss', ['from' => '2026-03-31', 'to' => '2026-03-01']))
        ->assertSessionHasErrors('to');
});

it('exports the profit and loss with its totals', function () {
    $csv = $this->actingAs($this->owner)
        ->get(route('reports.profit-loss.csv', ['from' => $this->today, 'to' => $this->today]))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Code,Item,"Quantity sold"')
        ->and($csv)->toContain('PANADOL-500,"Panadol 500mg",2,24.00,16.00,8.00')
        ->and($csv)->toContain('Net revenue');
});
