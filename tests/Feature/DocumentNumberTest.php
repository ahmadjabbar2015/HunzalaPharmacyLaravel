<?php

use App\Models\Sale;
use App\Support\BusinessDate;
use App\Support\DocumentNumber;
use Carbon\CarbonImmutable;

it('computes the business date from Karachi time, not UTC', function () {
    // 7:30 PM in Karachi is 2:30 PM UTC the same day.
    expect(BusinessDate::for('2026-09-24 14:30:00')->toDateString())->toBe('2026-09-24');

    // 12:10 AM in Karachi is 7:10 PM UTC the PREVIOUS day - a sale rung just
    // after midnight belongs to the new business day, not the old one.
    expect(BusinessDate::for('2026-09-24 19:10:00')->toDateString())->toBe('2026-09-25');
});

it('keeps a session running past midnight on one business date', function () {
    // Opened 11:50 PM Karachi (18:50 UTC), still trading at 12:10 AM (19:10 UTC).
    $opened = BusinessDate::for('2026-09-24 18:50:00')->toDateString();
    $later = BusinessDate::for('2026-09-24 19:10:00')->toDateString();

    // They fall on different business dates, which is exactly why a session is
    // dated by when it OPENED rather than by "today".
    expect($opened)->toBe('2026-09-24')
        ->and($later)->toBe('2026-09-25');
});

it('mints device-prefixed invoice numbers that increment within the day', function () {
    $day = '2026-09-24';

    expect(DocumentNumber::invoice($day, 'WEB'))->toBe('WEB-20260924-0001');

    Sale::create([
        'invoice_number' => 'WEB-20260924-0001',
        'sale_date' => $day,
        'sale_time' => CarbonImmutable::parse('2026-09-24 14:30:00'),
        'staff_member_uuid' => (string) Str::uuid(),
    ]);

    expect(DocumentNumber::invoice($day, 'WEB'))->toBe('WEB-20260924-0002');
});

it('never collides between two devices', function () {
    $day = '2026-09-24';

    expect(DocumentNumber::invoice($day, 'WEB'))->toBe('WEB-20260924-0001')
        ->and(DocumentNumber::invoice($day, 'PC1'))->toBe('PC1-20260924-0001');
});

it('resets the counter each day', function () {
    Sale::create([
        'invoice_number' => 'WEB-20260924-0001',
        'sale_date' => '2026-09-24',
        'sale_time' => CarbonImmutable::parse('2026-09-24 14:30:00'),
        'staff_member_uuid' => (string) Str::uuid(),
    ]);

    expect(DocumentNumber::invoice('2026-09-25', 'WEB'))->toBe('WEB-20260925-0001');
});

it('gives each document kind its own stream', function () {
    $day = '2026-09-24';

    expect(DocumentNumber::invoice($day, 'WEB'))->toBe('WEB-20260924-0001')
        ->and(DocumentNumber::return($day, 'WEB'))->toBe('WEB-R-20260924-0001')
        ->and(DocumentNumber::purchaseOrder($day, 'WEB'))->toBe('WEB-PO-20260924-0001')
        ->and(DocumentNumber::goodsReceipt($day, 'WEB'))->toBe('WEB-GRN-20260924-0001');
});

it('does not reissue a soft-deleted document number', function () {
    $sale = Sale::create([
        'invoice_number' => 'WEB-20260924-0001',
        'sale_date' => '2026-09-24',
        'sale_time' => CarbonImmutable::parse('2026-09-24 14:30:00'),
        'staff_member_uuid' => (string) Str::uuid(),
    ]);

    $sale->softDelete();

    // A voided invoice must not hand its printed number to the next sale.
    expect(DocumentNumber::invoice('2026-09-24', 'WEB'))->toBe('WEB-20260924-0002');
});
