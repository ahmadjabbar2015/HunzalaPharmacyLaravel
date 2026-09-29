<?php

/*
 * Ported from shared/services/report_service.py.
 *
 * The Python has no test file for reports - they were checked by looking at the
 * screen. These tests are therefore new, and they cover the two things looking
 * at the screen cannot: that the totals are exact to the paisa, and that a
 * report over N items still runs a fixed number of queries.
 */

use App\Exceptions\ReportException;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartLine;
use App\Services\ReportService;
use App\Services\ReturnLine;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\SessionService;
use App\Services\StockService;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->reports = app(ReportService::class);
    $this->stock = app(StockService::class);
    $this->sales = app(SaleService::class);

    $this->ahmed = User::factory()->create(['username' => 'ahmed', 'full_name' => 'Ahmed Khan']);
    $this->sara = User::factory()->create(['username' => 'sara', 'full_name' => 'Sara Bibi']);

    $this->panadol = Item::factory()->create([
        'item_code' => 'PANADOL-500',
        'item_name' => 'Panadol 500mg',
        'purchase_price' => '8.00',
        'sales_price' => '12.00',
        'reorder_level' => 10,
    ]);

    $this->panadolBatch = ItemBatch::factory()->for($this->panadol, 'item')->create([
        'batch_number' => 'B-1',
        'purchase_price' => '8.00',
        'expiry_date' => BusinessDate::for()->addYear()->toDateString(),
    ]);

    $this->stock->receive(
        itemUuid: $this->panadol->uuid,
        batchUuid: $this->panadolBatch->uuid,
        quantity: 100,
        deviceId: DEVICE,
    );

    $this->today = BusinessDate::today();
});

// ---------------------------------------------------------------------------
// daily sales
// ---------------------------------------------------------------------------

it('reports a day with no trade as zeroes rather than as nothing', function () {
    $report = $this->reports->dailySales($this->today);

    expect($report['total_count'])->toBe(0)
        ->and($report['total_net'])->toBe('0.00')
        ->and($report['return_total'])->toBe('0.00')
        ->and($report['sales'])->toBe([])
        ->and($report['by_staff'])->toBe([]);
});

it('totals a day of sales exactly', function () {
    $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 3, '12.10', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
    );

    $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 1, '12.10', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
    );

    $report = $this->reports->dailySales($this->today);

    // 4 × 12.10 = 48.40. Three of these amounts are not representable as
    // floats, which is the whole point of Money - 36.30 + 12.10 in float
    // arithmetic is 48.400000000000006.
    expect($report['total_count'])->toBe(2)
        ->and($report['total_subtotal'])->toBe('48.40')
        ->and($report['total_net'])->toBe('48.40')
        ->and($report['total_discount'])->toBe('0.00');
});

it('breaks the day down by staff, payment method and medicine', function () {
    $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 2, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
    );

    $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 5, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->sara->uuid,
        deviceId: DEVICE,
        paymentMethod: 'mobile',
        paymentReference: 'EP-1',
    );

    $report = $this->reports->dailySales($this->today);

    expect($report['by_staff']['Ahmed Khan'])->toBe(['count' => 1, 'net' => '24.00'])
        ->and($report['by_staff']['Sara Bibi'])->toBe(['count' => 1, 'net' => '60.00'])
        ->and($report['by_payment']['cash'])->toBe(['count' => 1, 'net' => '24.00'])
        ->and($report['by_payment']['mobile'])->toBe(['count' => 1, 'net' => '60.00'])
        ->and($report['by_medicine']['Panadol 500mg'])->toBe(['qty' => 7, 'net' => '84.00']);
});

it('lists returns beside the takings instead of netting them off', function () {
    $sale = $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 4, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
    );

    app(ReturnService::class)->create(
        originalSaleUuid: $sale->uuid,
        lines: [new ReturnLine($sale->lines()->first()->uuid, 1)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,

    );

    $report = $this->reports->dailySales($this->today);

    // The takings are untouched. A sale is never edited; the return is its own
    // record, and an owner who saw only the net figure could not tell a slow
    // day from a day of refunds.
    expect($report['total_net'])->toBe('48.00')
        ->and($report['return_count'])->toBe(1)
        ->and($report['return_total'])->toBe('12.00');
});

it('leaves out sales from other days', function () {
    $sale = $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 1, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
    );

    $yesterday = BusinessDate::for()->subDay()->toDateString();
    $sale->forceFill(['sale_date' => $yesterday])->save();

    expect($this->reports->dailySales($this->today)['total_count'])->toBe(0)
        ->and($this->reports->dailySales($yesterday)['total_count'])->toBe(1);
});

it('leaves out a deleted sale', function () {
    $sale = $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 1, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
    );

    $sale->forceFill(['is_deleted' => true])->save();

    expect($this->reports->dailySales($this->today)['total_count'])->toBe(0);
});

it('runs a fixed number of queries whatever the day held', function () {
    foreach (range(1, 12) as $n) {
        $this->sales->create(
            lines: [new CartLine($this->panadol->uuid, 1, '12.00', $this->panadolBatch->uuid)],
            staffMemberUuid: $n % 2 === 0 ? $this->ahmed->uuid : $this->sara->uuid,
            deviceId: DEVICE,
        );
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->reports->dailySales($this->today);

    // sales + staff + customers + medicine breakdown + returns. §5 trap 2: a
    // per-sale lookup here is 500 queries on a busy Saturday.
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);

    DB::disableQueryLog();
});

// ---------------------------------------------------------------------------
// session summary
// ---------------------------------------------------------------------------

it('refuses a session uuid that was never opened', function () {
    $this->reports->sessionSummary('no-such-session');
})->throws(ReportException::class);

it('splits a session into cash and mobile, because only cash is in the drawer', function () {
    $sessions = app(SessionService::class);
    $drawer = $sessions->open($this->ahmed->uuid, '1000.00', DEVICE);

    $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 5, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
        sessionUuid: $drawer->uuid,
    );

    $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 2, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->sara->uuid,
        deviceId: DEVICE,
        sessionUuid: $drawer->uuid,
        paymentMethod: 'mobile',
        paymentReference: 'EP-2',
    );

    $report = $this->reports->sessionSummary($drawer->uuid);

    expect($report['sale_count'])->toBe(2)
        ->and($report['total_net'])->toBe('84.00')
        ->and($report['cash_sales'])->toBe('60.00')
        ->and($report['mobile_sales'])->toBe('24.00')
        ->and($report['opened_by'])->toBe('Ahmed Khan')
        ->and($report['status'])->toBe('open');
});

it('shows no variance at all for a drawer that has not been counted', function () {
    $drawer = app(SessionService::class)->open($this->ahmed->uuid, '1000.00', DEVICE);

    $report = $this->reports->sessionSummary($drawer->uuid);

    // Null, not 0.00. A zero variance reads as "it balanced", which is the one
    // thing an uncounted drawer does not mean.
    expect($report['cash_variance'])->toBeNull()
        ->and($report['expected_cash'])->toBeNull()
        ->and($report['closing_cash_counted'])->toBeNull()
        ->and($report['closed_by'])->toBeNull();
});

it('reports the variance the close recorded rather than working it out again', function () {
    $sessions = app(SessionService::class);
    $drawer = $sessions->open($this->ahmed->uuid, '1000.00', DEVICE);

    $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 5, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
        sessionUuid: $drawer->uuid,
    );

    $closed = $sessions->close($this->sara->uuid, '1055.00', DEVICE, $drawer);

    $report = $this->reports->sessionSummary($drawer->uuid);

    expect($report['expected_cash'])->toBe('1060.00')
        ->and($report['closing_cash_counted'])->toBe('1055.00')
        // Short by five, and signed so it reads as a shortfall.
        ->and($report['cash_variance'])->toBe('-5.00')
        ->and($report['cash_variance'])->toBe(Money::format($closed->cash_variance))
        ->and($report['closed_by'])->toBe('Sara Bibi')
        ->and($report['status'])->not->toBe('open');
});

// ---------------------------------------------------------------------------
// inventory valuation
// ---------------------------------------------------------------------------

it('values stock at cost from the ledger, not from the cache column', function () {
    // Corrupt the cache to the largest lie it can tell. The report must not
    // notice (RULE 2).
    $this->panadol->forceFill(['current_stock_qty' => 99999])->save();

    $report = $this->reports->inventory();

    expect($report['total_lines'])->toBe(1)
        ->and($report['items'][0]['derived_qty'])->toBe(100)
        ->and($report['items'][0]['stock_value'])->toBe('800.00')
        ->and($report['total_value'])->toBe('800.00');
});

it('flags an item at or below its reorder level', function () {
    $this->stock->sell(
        itemUuid: $this->panadol->uuid,
        batchUuid: $this->panadolBatch->uuid,
        quantity: 91,
        deviceId: DEVICE,
    );

    $report = $this->reports->inventory();

    // 9 left against a reorder level of 10.
    expect($report['items'][0]['derived_qty'])->toBe(9)
        ->and($report['items'][0]['is_low_stock'])->toBeTrue()
        ->and($report['low_stock_count'])->toBe(1);
});

it('drops emptied batches but keeps the item', function () {
    $this->stock->sell(
        itemUuid: $this->panadol->uuid,
        batchUuid: $this->panadolBatch->uuid,
        quantity: 100,
        deviceId: DEVICE,
    );

    $report = $this->reports->inventory();

    expect($report['items'])->toHaveCount(1)
        ->and($report['items'][0]['derived_qty'])->toBe(0)
        ->and($report['items'][0]['batches'])->toBe([]);
});

it('marks a batch expiring inside the alert window', function () {
    Setting::create(['expiry_alert_days' => 30, 'origin_device_id' => DEVICE]);

    $soon = ItemBatch::factory()->for($this->panadol, 'item')->create([
        'batch_number' => 'B-SOON',
        'purchase_price' => '8.00',
        'expiry_date' => BusinessDate::for()->addDays(10)->toDateString(),
    ]);

    $this->stock->receive(
        itemUuid: $this->panadol->uuid, batchUuid: $soon->uuid, quantity: 5, deviceId: DEVICE
    );

    $report = $this->reports->inventory();
    $batches = collect($report['items'][0]['batches'])->keyBy('batch_number');

    expect($batches['B-SOON']['is_expiring'])->toBeTrue()
        ->and($batches['B-SOON']['is_expired'])->toBeFalse()
        ->and($batches['B-1']['is_expiring'])->toBeFalse()
        ->and($report['expiring_count'])->toBe(1);
});

it('marks a batch already past its expiry', function () {
    $expired = ItemBatch::factory()->for($this->panadol, 'item')->create([
        'batch_number' => 'B-OLD',
        'purchase_price' => '8.00',
        'expiry_date' => BusinessDate::for()->subDays(3)->toDateString(),
    ]);

    $this->stock->receive(
        itemUuid: $this->panadol->uuid, batchUuid: $expired->uuid, quantity: 5, deviceId: DEVICE
    );

    $batches = collect($this->reports->inventory()['items'][0]['batches'])->keyBy('batch_number');

    expect($batches['B-OLD']['is_expired'])->toBeTrue()
        ->and($batches['B-OLD']['is_expiring'])->toBeTrue();
});

it('leaves out a deactivated item', function () {
    $this->panadol->forceFill(['is_active' => false])->save();

    expect($this->reports->inventory()['total_lines'])->toBe(0);
});

it('values the whole catalogue in a fixed number of queries', function () {
    foreach (range(1, 15) as $n) {
        $item = Item::factory()->create(['item_code' => "X-{$n}", 'purchase_price' => '5.00']);
        $batch = ItemBatch::factory()->for($item, 'item')->create();
        $this->stock->receive(
            itemUuid: $item->uuid, batchUuid: $batch->uuid, quantity: 10, deviceId: DEVICE
        );
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $report = $this->reports->inventory();

    expect($report['total_lines'])->toBe(16)
        // settings + items + batches + two aggregate SUMs. The Python called a
        // SUM() per item and another per batch, which is why its valuation
        // screen took seconds.
        ->and(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);

    DB::disableQueryLog();
});

// ---------------------------------------------------------------------------
// profit & loss
// ---------------------------------------------------------------------------

it('refuses a range that runs backwards', function () {
    $this->reports->profitLoss('2026-03-31', '2026-03-01');
})->throws(ReportException::class);

it('costs a sale from the batch it actually came from', function () {
    $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 10, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
    );

    // The catalogue cost changes after the sale, as it does every time a
    // supplier raises a price. The profit already earned must not move.
    $this->panadol->forceFill(['purchase_price' => '11.00'])->save();

    $report = $this->reports->profitLoss($this->today, $this->today);

    expect($report['rows'])->toHaveCount(1)
        ->and($report['rows'][0]['qty_sold'])->toBe(10)
        ->and($report['rows'][0]['revenue'])->toBe('120.00')
        ->and($report['rows'][0]['cogs'])->toBe('80.00')
        ->and($report['rows'][0]['gross_profit'])->toBe('40.00')
        ->and($report['total_gross_profit'])->toBe('40.00');
});

it('aggregates a medicine sold across several sales into one row', function () {
    foreach (range(1, 3) as $ignored) {
        $this->sales->create(
            lines: [new CartLine($this->panadol->uuid, 2, '12.00', $this->panadolBatch->uuid)],
            staffMemberUuid: $this->ahmed->uuid,
            deviceId: DEVICE,
        );
    }

    $report = $this->reports->profitLoss($this->today, $this->today);

    expect($report['rows'])->toHaveCount(1)
        ->and($report['rows'][0]['qty_sold'])->toBe(6)
        ->and($report['rows'][0]['revenue'])->toBe('72.00')
        ->and($report['rows'][0]['cogs'])->toBe('48.00');
});

it('takes discounts and returns off net revenue but not off gross profit', function () {
    $sale = $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 10, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
        discountType: 'fixed',
        discountAmount: '20.00',
        discountReason: 'regular',
    );

    app(ReturnService::class)->create(
        originalSaleUuid: $sale->uuid,
        lines: [new ReturnLine($sale->lines()->first()->uuid, 1)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,

    );

    $report = $this->reports->profitLoss($this->today, $this->today);

    expect($report['total_revenue'])->toBe('120.00')
        ->and($report['total_discount'])->toBe('20.00')
        ->and($report['total_returns'])->toBe('12.00')
        ->and($report['net_revenue'])->toBe('88.00')
        // The returned box went back on the shelf, so its cost belongs to
        // whatever sells it next. Crediting it here would count it twice.
        ->and($report['total_cogs'])->toBe('80.00')
        ->and($report['total_gross_profit'])->toBe('40.00');
});

it('treats a line with no batch behind it as costing nothing rather than crashing', function () {
    $sale = $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 2, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
    );

    $sale->lines()->first()->forceFill(['batch_uuid' => null])->save();

    $report = $this->reports->profitLoss($this->today, $this->today);

    // A perfect margin, which is the point: it is visibly wrong on the row
    // rather than quietly absorbed into a plausible total.
    expect($report['total_cogs'])->toBe('0.00')
        ->and($report['total_gross_profit'])->toBe('24.00');
});

it('covers only the range it was asked for', function () {
    $sale = $this->sales->create(
        lines: [new CartLine($this->panadol->uuid, 1, '12.00', $this->panadolBatch->uuid)],
        staffMemberUuid: $this->ahmed->uuid,
        deviceId: DEVICE,
    );

    $sale->forceFill(['sale_date' => BusinessDate::for()->subDays(10)->toDateString()])->save();

    expect($this->reports->profitLoss($this->today, $this->today)['rows'])->toBe([])
        ->and($this->reports->profitLoss(
            BusinessDate::for()->subDays(30)->toDateString(), $this->today
        )['rows'])->toHaveCount(1);
});
