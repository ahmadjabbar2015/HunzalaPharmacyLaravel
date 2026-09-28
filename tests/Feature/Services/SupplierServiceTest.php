<?php

/*
 * Ported from tests/test_supplier_service.py.
 *
 * The outstanding balance is derived from the rows every time, never stored -
 * the same principle the stock ledger rests on. A drifted supplier balance is an
 * argument with someone the shop has to keep buying from.
 */

use App\Exceptions\SupplierException;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DirectLine;
use App\Services\ItemService;
use App\Services\PurchaseService;
use App\Services\SupplierService;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->suppliers = app(SupplierService::class);

    $this->supplier = $this->suppliers->create(
        supplierName: 'Abbott Distributors',
        deviceId: DEVICE,
        contactPerson: 'Mr Khan',
        phone: '0300-1112223',
        openingBalance: '5000.00',
    );

    $this->item = app(ItemService::class)->create(
        itemCode: 'PANADOL-500', itemName: 'Panadol 500mg', deviceId: DEVICE,
    );
});

/** Receive $qty at $cost from this supplier, with no prior order. */
function receiveFrom(string $supplierUuid, string $itemUuid, int $qty, string $cost, ?string $batch = null): void
{
    app(PurchaseService::class)->receiveDirect(
        supplierUuid: $supplierUuid,
        deviceId: DEVICE,
        lines: [new DirectLine($itemUuid, $batch ?? 'B-'.fake()->unique()->numerify('####'), '2027-01-01', $qty, $cost)],
    );
}

// ---------------------------------------------------------------------------
// creating and editing
// ---------------------------------------------------------------------------

it('creates a supplier', function () {
    expect($this->supplier->supplier_name)->toBe('Abbott Distributors')
        ->and($this->supplier->contact_person)->toBe('Mr Khan')
        ->and($this->supplier->opening_balance)->toBe('5000.00')
        ->and($this->supplier->is_active)->toBeTrue();
});

it('refuses a blank supplier name', function () {
    expect(fn () => $this->suppliers->create(supplierName: '   ', deviceId: DEVICE))
        ->toThrow(SupplierException::class);
});

it('trims the supplier name', function () {
    $supplier = $this->suppliers->create(supplierName: '  Getz Pharma  ', deviceId: DEVICE);

    expect($supplier->supplier_name)->toBe('Getz Pharma');
});

it('defaults the opening balance to zero', function () {
    $supplier = $this->suppliers->create(supplierName: 'New Supplier', deviceId: DEVICE);

    expect($supplier->opening_balance)->toBe('0.00');
});

it('applies an edit', function () {
    $this->suppliers->update($this->supplier->uuid, [
        'contact_person' => 'Mrs Khan',
        'payment_terms' => '30 days',
    ]);

    expect($this->supplier->fresh()->contact_person)->toBe('Mrs Khan')
        ->and($this->supplier->fresh()->payment_terms)->toBe('30 days');
});

it('refuses to edit the opening balance', function () {
    // It is the agreed starting position. Editing it would silently rewrite
    // every balance computed since, with no record of the change.
    expect(fn () => $this->suppliers->update($this->supplier->uuid, ['opening_balance' => '0.00']))
        ->toThrow(SupplierException::class);
});

it('refuses an unknown field or a blank name on edit', function () {
    expect(fn () => $this->suppliers->update($this->supplier->uuid, ['nope' => 1]))
        ->toThrow(SupplierException::class);

    expect(fn () => $this->suppliers->update($this->supplier->uuid, ['supplier_name' => '  ']))
        ->toThrow(SupplierException::class);
});

it('refuses to edit an unknown supplier', function () {
    expect(fn () => $this->suppliers->update('no-such-supplier', ['phone' => '1']))
        ->toThrow(SupplierException::class);
});

// ---------------------------------------------------------------------------
// listing
// ---------------------------------------------------------------------------

it('lists active suppliers by name', function () {
    $this->suppliers->create(supplierName: 'Getz Pharma', deviceId: DEVICE);
    $this->suppliers->create(supplierName: 'Zafa Pharmaceutical', deviceId: DEVICE);

    expect($this->suppliers->list()->pluck('supplier_name')->all())
        ->toBe(['Abbott Distributors', 'Getz Pharma', 'Zafa Pharmaceutical']);
});

it('excludes an inactive supplier unless asked', function () {
    $gone = $this->suppliers->create(supplierName: 'Closed Down', deviceId: DEVICE);
    $this->suppliers->update($gone->uuid, ['is_active' => false]);

    expect($this->suppliers->list()->pluck('supplier_name')->all())->toBe(['Abbott Distributors'])
        // A dormant supplier is still needed on old purchase history.
        ->and($this->suppliers->list(activeOnly: false))->toHaveCount(2);
});

it('searches suppliers by name, case-insensitively', function () {
    $this->suppliers->create(supplierName: 'Getz Pharma', deviceId: DEVICE);

    expect($this->suppliers->list(search: 'getz')->pluck('supplier_name')->all())->toBe(['Getz Pharma'])
        ->and($this->suppliers->list(search: 'PHARMA')->pluck('supplier_name')->all())->toBe(['Getz Pharma'])
        ->and($this->suppliers->list(search: 'nobody'))->toBeEmpty();
});

// ---------------------------------------------------------------------------
// the outstanding balance
// ---------------------------------------------------------------------------

it('starts at the opening balance', function () {
    expect($this->suppliers->outstandingBalance($this->supplier->uuid))->toBe('5000.00');
});

it('adds purchases and subtracts payments', function () {
    receiveFrom($this->supplier->uuid, $this->item->uuid, 100, '12.00');   // +1200

    expect($this->suppliers->outstandingBalance($this->supplier->uuid))->toBe('6200.00');

    $this->suppliers->recordPayment(
        supplierUuid: $this->supplier->uuid, amount: '2000.00',
        deviceId: DEVICE, paymentMethod: 'bank_transfer',
    );

    // 5000 opening + 1200 purchased - 2000 paid.
    expect($this->suppliers->outstandingBalance($this->supplier->uuid))->toBe('4200.00');
});

it('keeps the balance exact over many small movements', function () {
    // Accumulated as floats this drifts, and a supplier balance a few paisa out
    // is an argument nobody can settle from the screen.
    foreach (range(1, 100) as $n) {
        receiveFrom($this->supplier->uuid, $this->item->uuid, 3, '0.33', "B-{$n}");
    }

    // 5000 + 100 x (3 x 0.33) = 5000 + 99.00
    expect($this->suppliers->outstandingBalance($this->supplier->uuid))->toBe('5099.00');
});

it('reports zero for an unknown supplier', function () {
    expect($this->suppliers->outstandingBalance('no-such-supplier'))->toBe('0.00');
});

it('ignores another supplier\'s purchases and payments', function () {
    $other = $this->suppliers->create(supplierName: 'Getz Pharma', deviceId: DEVICE);
    receiveFrom($other->uuid, $this->item->uuid, 50, '10.00');

    expect($this->suppliers->outstandingBalance($this->supplier->uuid))->toBe('5000.00')
        ->and($this->suppliers->outstandingBalance($other->uuid))->toBe('500.00');
});

it('computes many balances in a bounded number of queries', function () {
    // The supplier list shows a balance on every row, so one balance query per
    // supplier is the same N+1 trap as derived stock.
    $uuids = [$this->supplier->uuid];

    foreach (range(1, 10) as $n) {
        $uuids[] = $this->suppliers->create(supplierName: "Supplier {$n}", deviceId: DEVICE)->uuid;
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $balances = $this->suppliers->outstandingBalances($uuids);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(3)
        ->and($balances[$this->supplier->uuid])->toBe('5000.00')
        ->and($balances[$uuids[1]])->toBe('0.00');

    DB::disableQueryLog();
});

// ---------------------------------------------------------------------------
// payments
// ---------------------------------------------------------------------------

it('records a payment', function () {
    $payment = $this->suppliers->recordPayment(
        supplierUuid: $this->supplier->uuid,
        amount: '1500.00',
        deviceId: DEVICE,
        paymentMethod: 'cheque',
        reference: 'CHQ-00123',
        notes: 'part payment against INV-771',
        performedByUserUuid: User::factory()->create()->uuid,
    );

    expect($payment->amount)->toBe('1500.00')
        ->and($payment->payment_method)->toBe('cheque')
        ->and($payment->reference)->toBe('CHQ-00123')
        ->and($payment->payment_date->toDateString())->toBe(BusinessDate::today());
});

it('accepts the wider set of supplier payment methods', function () {
    // Suppliers are commonly paid by bank transfer or cheque, unlike POS sales.
    foreach (['cash', 'mobile', 'bank_transfer', 'cheque'] as $method) {
        $payment = $this->suppliers->recordPayment(
            supplierUuid: $this->supplier->uuid, amount: '10.00',
            deviceId: DEVICE, paymentMethod: $method,
        );

        expect($payment->payment_method)->toBe($method);
    }
});

it('refuses an unknown payment method', function () {
    expect(fn () => $this->suppliers->recordPayment(
        supplierUuid: $this->supplier->uuid, amount: '10.00',
        deviceId: DEVICE, paymentMethod: 'barter',
    ))->toThrow(SupplierException::class);
});

it('refuses a zero payment', function () {
    // It records nothing.
    expect(fn () => $this->suppliers->recordPayment(
        supplierUuid: $this->supplier->uuid, amount: '0.00',
        deviceId: DEVICE, paymentMethod: 'cash',
    ))->toThrow(SupplierException::class);
});

it('allows a negative payment as a correction', function () {
    // A mistaken payment is corrected by a compensating entry, never by editing
    // the original - the same rule the stock ledger follows.
    $this->suppliers->recordPayment(
        supplierUuid: $this->supplier->uuid, amount: '2000.00', deviceId: DEVICE, paymentMethod: 'cash',
    );
    $this->suppliers->recordPayment(
        supplierUuid: $this->supplier->uuid, amount: '-500.00', deviceId: DEVICE,
        paymentMethod: 'cash', notes: 'corrects an overpayment on CHQ-00123',
    );

    // 5000 - 2000 + 500.
    expect($this->suppliers->outstandingBalance($this->supplier->uuid))->toBe('3500.00')
        ->and($this->suppliers->payments($this->supplier->uuid))->toHaveCount(2);
});

it('refuses a payment to an unknown supplier', function () {
    expect(fn () => $this->suppliers->recordPayment(
        supplierUuid: 'no-such-supplier', amount: '10.00', deviceId: DEVICE, paymentMethod: 'cash',
    ))->toThrow(SupplierException::class);
});

it('lists a supplier\'s payments, most recent first', function () {
    $this->suppliers->recordPayment(
        supplierUuid: $this->supplier->uuid, amount: '100.00', deviceId: DEVICE,
        paymentMethod: 'cash', paymentDate: '2026-01-01',
    );
    $this->suppliers->recordPayment(
        supplierUuid: $this->supplier->uuid, amount: '200.00', deviceId: DEVICE,
        paymentMethod: 'cash', paymentDate: '2026-06-01',
    );

    expect($this->suppliers->payments($this->supplier->uuid)->pluck('amount')->all())
        ->toBe(['200.00', '100.00']);
});
