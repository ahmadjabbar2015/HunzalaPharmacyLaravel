<?php

/*
 * Ported from tests/test_return_service.py.
 *
 * Three tests here have no Python counterpart - the cumulative-cap group - and
 * they cover a real bug in the Python rather than a Laravel concern. See
 * ReturnService::alreadyReturned().
 */

use App\Exceptions\ReturnException;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\CartLine;
use App\Services\ReturnLine;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Support\BusinessDate;

beforeEach(function () {
    $this->returns = app(ReturnService::class);
    $this->stock = app(StockService::class);

    $this->staff = User::factory()->create(['username' => 'ahmed']);

    $this->item = Item::factory()->create(['item_code' => 'PANADOL-500']);
    $this->batch = ItemBatch::factory()->for($this->item, 'item')->create();

    $this->stock->receive(
        itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 100, deviceId: DEVICE
    );

    // One sale of 5 at 100.00 to return against.
    $this->sale = app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 5, '100.00', $this->batch->uuid)],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    );

    $this->saleLine = $this->sale->lines()->first();
});

// ---------------------------------------------------------------------------
// finding the original sale
// ---------------------------------------------------------------------------

it('finds a sale by its invoice number', function () {
    expect($this->returns->findSaleByInvoice($this->sale->invoice_number)->uuid)->toBe($this->sale->uuid)
        // Staff read the number off a creased receipt and add spaces.
        ->and($this->returns->findSaleByInvoice('  '.$this->sale->invoice_number.'  ')->uuid)
        ->toBe($this->sale->uuid);
});

it('returns nothing for an unknown invoice number', function () {
    expect($this->returns->findSaleByInvoice('WEB-19990101-0001'))->toBeNull();
});

it('finds recent sales by phone number', function () {
    // The path when the receipt is lost, which is most of the time.
    $customer = Customer::create([
        'phone_number' => '0300-1234567',
        'primary_contact_name' => 'Fatima',
        'origin_device_id' => DEVICE,
    ]);

    $theirs = app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 1, '10.00', $this->batch->uuid)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE, customerUuid: $customer->uuid,
    );

    $found = $this->returns->findSalesByPhone('0300-1234567');

    expect($found->pluck('uuid')->all())->toBe([$theirs->uuid]);
});

it('returns nothing for an unknown phone number', function () {
    expect($this->returns->findSalesByPhone('0300-0000000'))->toBeEmpty();
});

// ---------------------------------------------------------------------------
// validation
// ---------------------------------------------------------------------------

it('refuses a return with no items', function () {
    expect(fn () => $this->returns->create(
        originalSaleUuid: $this->sale->uuid, lines: [],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(ReturnException::class);
});

it('refuses a return against an unknown sale', function () {
    expect(fn () => $this->returns->create(
        originalSaleUuid: 'no-such-sale',
        lines: [new ReturnLine($this->saleLine->uuid, 1)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(ReturnException::class);
});

it('refuses a line that is not on the sale', function () {
    $other = app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 1, '10.00', $this->batch->uuid)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // Returning another sale's line against this one would refund a line twice.
    expect(fn () => $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($other->lines()->first()->uuid, 1)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(ReturnException::class);
});

it('refuses more than was sold', function () {
    expect(fn () => $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 6)],   // only 5 were sold
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(ReturnException::class);
});

it('refuses a non-positive return quantity', function () {
    expect(fn () => $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 0)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(ReturnException::class);

    // A negative return would take stock back off the shelf AND pay the
    // customer for it.
    expect(fn () => $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, -2)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(ReturnException::class);
});

it('writes nothing when a later line fails validation', function () {
    // Everything is validated before anything is written. A return that failed
    // on its third line having refunded the first two would leave the customer
    // part-refunded with no record saying so.
    expect(fn () => $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [
            new ReturnLine($this->saleLine->uuid, 2),
            new ReturnLine('no-such-line', 1),
        ],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(ReturnException::class);

    expect(SaleReturn::count())->toBe(0)
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(95);
});

// ---------------------------------------------------------------------------
// the cumulative cap - a bug in the Python, fixed here
// ---------------------------------------------------------------------------

it('counts earlier returns against the cap', function () {
    /*
     * THE BUG THIS GUARDS. The Python caps each return against the ORIGINAL line
     * quantity only, so a line of 5 can be returned 5 units at a time,
     * repeatedly - paying out more than the customer ever paid and inflating
     * stock with goods that never came back. Nothing in the Python suite covers
     * a second return against the same line, which is why it survived.
     */
    $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 3)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // 2 of the 5 remain returnable, so 3 more must be refused.
    expect(fn () => $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 3)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(ReturnException::class);

    // And exactly the remaining 2 are still allowed.
    $second = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 2)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    expect($second->total_amount)->toBe('200.00')
        // All 5 back on the shelf: 100 - 5 + 3 + 2.
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(100);
});

it('refuses anything once a line is fully returned', function () {
    $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 5)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    expect(fn () => $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 1)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(ReturnException::class);
});

it('caps a line named twice within one return', function () {
    // Checked in isolation, 3 and 3 each pass against a line of 5. Accumulated,
    // they do not - which is the only reading that protects the money.
    expect(fn () => $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [
            new ReturnLine($this->saleLine->uuid, 3),
            new ReturnLine($this->saleLine->uuid, 3),
        ],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    ))->toThrow(ReturnException::class);
});

it('reports what remains returnable on each line', function () {
    $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 2)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // The return form must show this, not the original quantity - showing 5
    // here is how the same line gets refunded twice.
    $row = $this->returns->returnableItems($this->sale->uuid)->first();

    expect($row['line']->uuid)->toBe($this->saleLine->uuid)
        ->and($row['returned'])->toBe(2)
        ->and($row['returnable'])->toBe(3);
});

it('reports a full quantity as returnable before any return', function () {
    $row = $this->returns->returnableItems($this->sale->uuid)->first();

    expect($row['returned'])->toBe(0)
        ->and($row['returnable'])->toBe(5);
});

// ---------------------------------------------------------------------------
// the write
// ---------------------------------------------------------------------------

it('restores stock with a positive ledger row', function () {
    expect($this->stock->quantityFor($this->item->uuid))->toBe(95);

    $return = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 5)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    $ledger = StockTransaction::where('reference_uuid', $return->uuid)->get();

    expect($ledger)->toHaveCount(1)
        ->and($ledger->first()->qty_change)->toBe(5)
        ->and($ledger->first()->transaction_type)->toBe('return')
        ->and($ledger->first()->reference_type)->toBe('return')
        ->and($this->stock->quantityFor($this->item->uuid))->toBe(100);
});

it('returns stock to the batch it left from', function () {
    // Returned goods carry the expiry of the lot they came from; adding them to
    // an arbitrary batch would corrupt FEFO and the expiry alerts.
    $return = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 5)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    expect($return->lines()->first()->batch_uuid)->toBe($this->batch->uuid)
        ->and($this->stock->batchQuantityFor($this->batch->uuid))->toBe(100);
});

it('restores only part of the stock on a partial return', function () {
    $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 2)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // 100 - 5 sold + 2 back.
    expect($this->stock->quantityFor($this->item->uuid))->toBe(97);
});

it('refunds at the original sale rate', function () {
    // A price rise between sale and return must not hand the customer more than
    // they paid, and a price cut must not short them.
    $this->item->forceFill(['sales_price' => '999.00'])->save();

    $return = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 2)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    expect($return->total_amount)->toBe('200.00')
        ->and($return->lines()->first()->rate)->toBe('100.00');
});

it('totals a multi-line return exactly', function () {
    $second = Item::factory()->create(['item_code' => 'BRUFEN']);
    $secondBatch = ItemBatch::factory()->for($second, 'item')->create();
    $this->stock->receive(itemUuid: $second->uuid, batchUuid: $secondBatch->uuid, quantity: 50, deviceId: DEVICE);

    $sale = app(SaleService::class)->create(
        lines: [
            new CartLine($this->item->uuid, 3, '19.99', $this->batch->uuid),
            new CartLine($second->uuid, 7, '0.33', $secondBatch->uuid),
        ],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    $return = $this->returns->create(
        originalSaleUuid: $sale->uuid,
        lines: $sale->lines->map(fn (SaleItem $l) => new ReturnLine($l->uuid, $l->quantity))->all(),
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // 3 x 19.99 + 7 x 0.33 = 59.97 + 2.31 = 62.28
    expect($return->total_amount)->toBe('62.28');
});

// ---------------------------------------------------------------------------
// the original sale is never edited
// ---------------------------------------------------------------------------

it('leaves the original sale amounts untouched', function () {
    $before = [
        'subtotal' => $this->sale->subtotal_amount,
        'net' => $this->sale->net_amount,
    ];

    $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 5)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    $this->sale->refresh();

    // The history of what was sold must stay true even after all of it came
    // back. Editing the amounts would also change the record hash.
    expect($this->sale->subtotal_amount)->toBe($before['subtotal'])
        ->and($this->sale->net_amount)->toBe($before['net'])
        ->and($this->sale->lines()->first()->quantity)->toBe(5);
});

it('flags the original sale as returned', function () {
    expect($this->sale->is_returned)->toBeFalse();

    $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 1)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // Set on a partial return too: the flag means "something came back", and
    // whoever is looking needs to go and see what.
    expect($this->sale->fresh()->is_returned)->toBeTrue();
});

// ---------------------------------------------------------------------------
// numbering
// ---------------------------------------------------------------------------

it('numbers returns with the device prefix and an R', function () {
    $return = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 1)],
        staffMemberUuid: $this->staff->uuid, deviceId: 'PC1',
    );

    $day = BusinessDate::for()->format('Ymd');

    // The R keeps a return number from ever being mistaken for an invoice.
    expect($return->return_number)->toBe("PC1-R-{$day}-0001");
});

it('increments return numbers', function () {
    $first = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 1)],
        staffMemberUuid: $this->staff->uuid, deviceId: 'PC1',
    );
    $second = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 1)],
        staffMemberUuid: $this->staff->uuid, deviceId: 'PC1',
    );

    expect($first->return_number)->toEndWith('-0001')
        ->and($second->return_number)->toEndWith('-0002');
});

it('carries the customer across from the original sale', function () {
    $customer = Customer::create([
        'phone_number' => '0321-9998887',
        'primary_contact_name' => 'Imran',
        'origin_device_id' => DEVICE,
    ]);

    $sale = app(SaleService::class)->create(
        lines: [new CartLine($this->item->uuid, 1, '10.00', $this->batch->uuid)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE, customerUuid: $customer->uuid,
    );

    $return = $this->returns->create(
        originalSaleUuid: $sale->uuid,
        lines: [new ReturnLine($sale->lines()->first()->uuid, 1)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // Copied rather than re-asked: the customer is whoever bought it.
    expect($return->customer_uuid)->toBe($customer->uuid);
});

it('records the refund method and who processed it', function () {
    $manager = User::factory()->manager()->create(['username' => 'bilal']);

    $return = $this->returns->create(
        originalSaleUuid: $this->sale->uuid,
        lines: [new ReturnLine($this->saleLine->uuid, 1)],
        staffMemberUuid: $manager->uuid,
        deviceId: DEVICE,
        refundMethod: 'mobile',
        paymentReference: 'EP-55221',
    );

    expect($return->staff_member_uuid)->toBe($manager->uuid)
        ->and($return->refund_method)->toBe('mobile')
        ->and($return->payment_reference)->toBe('EP-55221');
});
