<?php

/*
 * Ported from tests/test_sale_service.py.
 *
 * A sale is the moment money changes hands, so these tests care about two things
 * above all: that the whole sale lands or none of it does, and that the
 * arithmetic is exact.
 */

use App\Exceptions\SaleException;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StaffPresence;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\CartLine;
use App\Services\SaleService;
use App\Services\SessionService;
use App\Services\StockService;
use App\Support\BusinessDate;

beforeEach(function () {
    $this->sales = app(SaleService::class);
    $this->stock = app(StockService::class);

    $this->staff = User::factory()->create(['username' => 'ahmed']);

    $this->item = Item::factory()->create(['item_code' => 'PANADOL-500', 'sales_price' => '100.00']);
    $this->batch = ItemBatch::factory()->for($this->item, 'item')->create();

    $this->stock->receive(
        itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 100, deviceId: DEVICE
    );
});

/** A one-line cart at $rate x $qty. */
function cart(string $itemUuid, string $rate, int $qty = 1, ?string $batchUuid = null): array
{
    return [new CartLine(itemUuid: $itemUuid, quantity: $qty, rate: $rate, batchUuid: $batchUuid)];
}

// ---------------------------------------------------------------------------
// validation
// ---------------------------------------------------------------------------

it('refuses an empty cart', function () {
    expect(fn () => $this->sales->create(lines: [], staffMemberUuid: $this->staff->uuid, deviceId: DEVICE))
        ->toThrow(SaleException::class);
});

it('refuses a non-positive line quantity', function () {
    // A zero line is a UI slip; a negative one would put stock back while
    // charging the customer for it.
    expect(fn () => $this->sales->create(
        lines: cart($this->item->uuid, '100.00', 0),
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(SaleException::class);

    expect(fn () => $this->sales->create(
        lines: cart($this->item->uuid, '100.00', -2),
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(SaleException::class);
});

// ---------------------------------------------------------------------------
// the write
// ---------------------------------------------------------------------------

it('writes the header, the lines and the stock movement', function () {
    $sale = $this->sales->create(
        lines: [
            new CartLine($this->item->uuid, 3, '100.00', $this->batch->uuid),
            new CartLine($this->item->uuid, 2, '50.00', $this->batch->uuid),
        ],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    );

    expect($sale->subtotal_amount)->toBe('400.00')     // 300 + 100
        ->and($sale->net_amount)->toBe('400.00')
        ->and($sale->lines()->count())->toBe(2)
        // 100 received, 5 sold.
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(95);
});

it('links every ledger row back to the sale', function () {
    $sale = $this->sales->create(
        lines: cart($this->item->uuid, '100.00', 4, $this->batch->uuid),
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    );

    // Without this link a stock question can never be traced to the transaction
    // that caused it.
    $ledger = StockTransaction::where('reference_uuid', $sale->uuid)->get();

    expect($ledger)->toHaveCount(1)
        ->and($ledger->first()->reference_type)->toBe('sale')
        ->and($ledger->first()->qty_change)->toBe(-4)
        ->and($ledger->first()->performed_by_user_uuid)->toBe($this->staff->uuid);
});

it('records the batch each line drew from', function () {
    // Needed to trace cost of goods, and to know which lot a recall affects.
    $sale = $this->sales->create(
        lines: cart($this->item->uuid, '100.00', 2, $this->batch->uuid),
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    );

    expect($sale->lines()->first()->batch_uuid)->toBe($this->batch->uuid)
        ->and($this->stock->batchQuantityFor($this->batch->uuid))->toBe(98);
});

it('leaves nothing behind when a line fails mid-save', function () {
    /*
     * The sale is one transaction. A header with no stock movement is money
     * taken with no record of taking it; stock moved with no header is the
     * reverse. Neither may survive a failure.
     *
     * The second line names an item that does not exist, so its ledger insert
     * violates the foreign key after the header and the first line are written.
     */
    $before = $this->stock->quantityFor($this->item->uuid);

    expect(fn () => $this->sales->create(
        lines: [
            new CartLine($this->item->uuid, 2, '100.00', $this->batch->uuid),
            new CartLine('no-such-item-uuid', 1, '10.00'),
        ],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    ))->toThrow(Exception::class);

    expect(Sale::count())->toBe(0)
        ->and(SaleItem::count())->toBe(0)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe($before);
});

// ---------------------------------------------------------------------------
// invoice numbering
// ---------------------------------------------------------------------------

it('numbers invoices with the device prefix and increments them', function () {
    $first = $this->sales->create(
        lines: cart($this->item->uuid, '10.00'), staffMemberUuid: $this->staff->uuid, deviceId: 'PC1',
    );
    $second = $this->sales->create(
        lines: cart($this->item->uuid, '10.00'), staffMemberUuid: $this->staff->uuid, deviceId: 'PC1',
    );

    $day = BusinessDate::for()->format('Ymd');

    expect($first->invoice_number)->toBe("PC1-{$day}-0001")
        ->and($second->invoice_number)->toBe("PC1-{$day}-0002");
});

it('keeps two devices from minting the same invoice number', function () {
    // The device prefix is the whole reason two offline tills cannot collide.
    $onPc = $this->sales->create(
        lines: cart($this->item->uuid, '10.00'), staffMemberUuid: $this->staff->uuid, deviceId: 'PC1',
    );
    $onWeb = $this->sales->create(
        lines: cart($this->item->uuid, '10.00'), staffMemberUuid: $this->staff->uuid, deviceId: 'WEB',
    );

    expect($onPc->invoice_number)->toStartWith('PC1-')
        ->and($onWeb->invoice_number)->toStartWith('WEB-')
        ->and($onPc->invoice_number)->not->toBe($onWeb->invoice_number)
        // Each device counts its own stream, so both are the day's first.
        ->and($onWeb->invoice_number)->toEndWith('-0001');
});

// ---------------------------------------------------------------------------
// discounts
// ---------------------------------------------------------------------------

it('applies a fixed discount', function () {
    $sale = $this->sales->create(
        lines: cart($this->item->uuid, '100.00', 3),
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        discountType: 'fixed', discountAmount: 50,
    );

    expect($sale->subtotal_amount)->toBe('300.00')
        ->and($sale->discount_amount)->toBe('50.00')
        ->and($sale->net_amount)->toBe('250.00');
});

it('applies a percentage discount', function () {
    $sale = $this->sales->create(
        lines: cart($this->item->uuid, '100.00', 2),
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        discountType: 'percentage', discountAmount: 10,
    );

    // Stored as the resolved RUPEE figure, not the percentage: a receipt has to
    // show what came off, and a later reprint must not recompute it.
    expect($sale->discount_amount)->toBe('20.00')
        ->and($sale->net_amount)->toBe('180.00');
});

it('resolves an awkward percentage to the paisa', function () {
    $sale = $this->sales->create(
        lines: cart($this->item->uuid, '333.33', 1),
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        discountType: 'percentage', discountAmount: '7.5',
    );

    // 7.5% of 333.33 = 24.99975, which must round to 25.00 rather than truncate
    // to 24.99. Float arithmetic here is what puts a till a few paisa out.
    expect($sale->discount_amount)->toBe('25.00')
        ->and($sale->net_amount)->toBe('308.33');
});

it('refuses a discount above the subtotal', function () {
    // Almost always a typo - 500 where 50 was meant. Rejected rather than
    // clamped, because silently giving the stock away is the worse outcome.
    expect(fn () => $this->sales->create(
        lines: cart($this->item->uuid, '100.00'),
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        discountType: 'fixed', discountAmount: 150,
    ))->toThrow(SaleException::class);
});

it('allows a discount of exactly the subtotal', function () {
    // A net of zero is a legitimate giveaway - a replacement for a damaged box.
    // Only NEGATIVE is impossible.
    $sale = $this->sales->create(
        lines: cart($this->item->uuid, '100.00'),
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        discountType: 'fixed', discountAmount: 100,
    );

    expect($sale->net_amount)->toBe('0.00');
});

it('refuses an unknown discount type', function () {
    expect(fn () => $this->sales->create(
        lines: cart($this->item->uuid, '100.00'),
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        discountType: 'loyalty', discountAmount: 10,
    ))->toThrow(SaleException::class);
});

it('treats a zero discount as no discount', function () {
    $sale = $this->sales->create(
        lines: cart($this->item->uuid, '100.00'),
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        discountType: 'fixed', discountAmount: 0,
    );

    expect($sale->discount_amount)->toBe('0.00')
        ->and($sale->net_amount)->toBe('100.00');
});

// ---------------------------------------------------------------------------
// exact money over a long cart - LARAVEL_PLAN.md §5
// ---------------------------------------------------------------------------

it('totals a long cart to the paisa', function () {
    // 40 lines at a rate with no exact binary representation. Summed as floats
    // this lands a few paisa out - small, consistent, and invisible until
    // someone counts the drawer.
    $lines = [];

    foreach (range(1, 40) as $ignored) {
        $lines[] = new CartLine($this->item->uuid, 3, '19.99', $this->batch->uuid);
    }

    $sale = $this->sales->create(
        lines: $lines, staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // 40 x (3 x 19.99) = 40 x 59.97 = 2398.80
    expect($sale->subtotal_amount)->toBe('2398.80')
        ->and($sale->net_amount)->toBe('2398.80')
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(100 - 120);
});

it('rounds each line once rather than the rate', function () {
    $sale = $this->sales->create(
        lines: cart($this->item->uuid, '19.995', 33, $this->batch->uuid),
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // Rounding the rate first gives 33 x 20.00 = 660.00. Rounding once at the
    // end gives 659.84, which is what the shelf label implies.
    expect($sale->subtotal_amount)->toBe('659.84');
});

// ---------------------------------------------------------------------------
// attribution and payment
// ---------------------------------------------------------------------------

it('records who completed the sale and how it was paid', function () {
    $sale = $this->sales->create(
        lines: cart($this->item->uuid, '100.00'),
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
        paymentMethod: 'mobile',
        paymentReference: 'EP-99881',
        discountReason: 'regular customer',
        discountType: 'fixed',
        discountAmount: 10,
        discountPinVerified: true,
    );

    expect($sale->staff_member_uuid)->toBe($this->staff->uuid)
        ->and($sale->payment_method)->toBe('mobile')
        ->and($sale->payment_reference)->toBe('EP-99881')
        ->and($sale->discount_reason)->toBe('regular customer')
        ->and($sale->discount_pin_verified)->toBeTrue();
});

it('dates the sale by the Karachi business date', function () {
    // 00:30 Karachi on the 2nd is 19:30 UTC on the 1st. The sale belongs to the
    // 2nd's takings; dating it by UTC would file it under the 1st.
    $this->travelTo('2026-09-01 19:30:00');

    $sale = $this->sales->create(
        lines: cart($this->item->uuid, '100.00'), staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    expect($sale->sale_date->toDateString())->toBe('2026-09-02')
        ->and($sale->invoice_number)->toContain('20260902');
});

// ---------------------------------------------------------------------------
// presence
// ---------------------------------------------------------------------------

it('checks the staff member in when they sell during an open session', function () {
    $drawer = app(SessionService::class)->open(
        userUuid: $this->staff->uuid, openingFloat: '500.00', deviceId: DEVICE,
    );

    $this->sales->create(
        lines: cart($this->item->uuid, '100.00'),
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
        sessionUuid: $drawer->uuid,
    );

    // They are demonstrably on the floor - they just served a customer - so
    // asking would be a question with one possible answer.
    expect(StaffPresence::where('session_uuid', $drawer->uuid)
        ->where('user_uuid', $this->staff->uuid)
        ->whereNull('checked_out_at')
        ->exists())->toBeTrue();
});

it('does not check anyone in when there is no session', function () {
    // Presence outside a drawer session is meaningless.
    $this->sales->create(
        lines: cart($this->item->uuid, '100.00'), staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    expect(StaffPresence::count())->toBe(0);
});

it('does not check the same person in twice over several sales', function () {
    $drawer = app(SessionService::class)->open(
        userUuid: $this->staff->uuid, openingFloat: '500.00', deviceId: DEVICE,
    );

    foreach (range(1, 3) as $ignored) {
        $this->sales->create(
            lines: cart($this->item->uuid, '10.00'),
            staffMemberUuid: $this->staff->uuid, deviceId: DEVICE, sessionUuid: $drawer->uuid,
        );
    }

    expect(StaffPresence::where('user_uuid', $this->staff->uuid)->count())->toBe(1);
});
