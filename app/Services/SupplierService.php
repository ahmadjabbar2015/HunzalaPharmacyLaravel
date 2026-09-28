<?php

namespace App\Services;

use App\Exceptions\SupplierException;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/*
 * Suppliers and what the shop owes them.
 *
 * The outstanding balance is DERIVED, never stored - the same principle the
 * stock ledger rests on. A stored balance drifts, and a supplier balance that
 * has drifted is an argument with someone the shop has to keep buying from.
 *
 * Ported from shared/services/supplier_service.py.
 */
class SupplierService
{
    /**
     * Fields an edit may touch.
     *
     * opening_balance is absent: it is the agreed starting position, and editing
     * it would silently rewrite every balance computed since.
     */
    public const EDITABLE_FIELDS = [
        'supplier_name', 'contact_person', 'phone', 'address',
        'payment_terms', 'is_active',
    ];

    public function create(
        string $supplierName,
        string $deviceId,
        ?string $contactPerson = null,
        ?string $phone = null,
        ?string $address = null,
        string|int|float $openingBalance = 0,
        ?string $paymentTerms = null,
    ): Supplier {
        $supplierName = trim($supplierName);

        if ($supplierName === '') {
            throw new SupplierException('supplier name is required');
        }

        $supplier = Supplier::create([
            'supplier_name' => $supplierName,
            'contact_person' => $contactPerson,
            'phone' => $phone,
            'address' => $address,
            'opening_balance' => Money::format($openingBalance),
            'payment_terms' => $paymentTerms,
            'origin_device_id' => $deviceId,
        ]);

        Log::info('supplier created', ['name' => $supplier->supplier_name]);

        return $supplier;
    }

    /**
     * @param  array<string, mixed>  $changes
     *
     * @throws SupplierException on an unknown field or a blank name
     */
    public function update(string $supplierUuid, array $changes): Supplier
    {
        $supplier = Supplier::find($supplierUuid);

        if ($supplier === null) {
            throw new SupplierException("supplier '{$supplierUuid}' not found");
        }

        $unknown = array_diff(array_keys($changes), self::EDITABLE_FIELDS);

        if ($unknown !== []) {
            throw new SupplierException('non-editable fields: '.implode(', ', $unknown));
        }

        if (array_key_exists('supplier_name', $changes) && trim((string) $changes['supplier_name']) === '') {
            throw new SupplierException('supplier name cannot be blank');
        }

        $supplier->fill($changes)->save();

        return $supplier;
    }

    /** @return Collection<int, Supplier> */
    public function list(?string $search = null, bool $activeOnly = true): Collection
    {
        return Supplier::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->when(
                $search !== null && trim($search) !== '',
                fn ($q) => $q->whereLike('supplier_name', '%'.trim($search).'%', caseSensitive: false)
            )
            ->orderBy('supplier_name')
            ->get();
    }

    public function find(string $supplierUuid): ?Supplier
    {
        return Supplier::find($supplierUuid);
    }

    // -----------------------------------------------------------------------
    // what the shop owes
    // -----------------------------------------------------------------------

    /**
     * opening_balance + purchases - payments.
     *
     * Recomputed from the rows every time, so it can never disagree with them.
     */
    public function outstandingBalance(string $supplierUuid): string
    {
        $supplier = Supplier::find($supplierUuid);

        if ($supplier === null) {
            return Money::ZERO;
        }

        $purchased = Purchase::query()->where('supplier_uuid', $supplierUuid)->sum('total_amount');
        $paid = SupplierPayment::query()->where('supplier_uuid', $supplierUuid)->sum('amount');

        return Money::sub(Money::add($supplier->opening_balance, $purchased), $paid);
    }

    /**
     * Outstanding balances for many suppliers in three queries rather than three
     * per supplier. The supplier list shows a balance on every row.
     *
     * @param  list<string>  $supplierUuids
     * @return array<string, string>
     */
    public function outstandingBalances(array $supplierUuids): array
    {
        if ($supplierUuids === []) {
            return [];
        }

        $openings = Supplier::query()
            ->whereIn('uuid', $supplierUuids)
            ->pluck('opening_balance', 'uuid');

        $purchases = Purchase::query()
            ->whereIn('supplier_uuid', $supplierUuids)
            ->groupBy('supplier_uuid')
            ->selectRaw('supplier_uuid, COALESCE(SUM(total_amount), 0) as total')
            ->pluck('total', 'supplier_uuid');

        $payments = SupplierPayment::query()
            ->whereIn('supplier_uuid', $supplierUuids)
            ->groupBy('supplier_uuid')
            ->selectRaw('supplier_uuid, COALESCE(SUM(amount), 0) as total')
            ->pluck('total', 'supplier_uuid');

        $balances = [];

        foreach ($supplierUuids as $uuid) {
            $balances[$uuid] = Money::sub(
                Money::add($openings[$uuid] ?? Money::ZERO, $purchases[$uuid] ?? Money::ZERO),
                $payments[$uuid] ?? Money::ZERO,
            );
        }

        return $balances;
    }

    // -----------------------------------------------------------------------
    // payments - append-only, like a sale
    // -----------------------------------------------------------------------

    /**
     * Record money paid to a supplier.
     *
     * A negative amount is allowed: it is how a mistaken payment is corrected,
     * by a compensating entry rather than by editing the original. Zero is not,
     * because it records nothing.
     *
     * @throws SupplierException on a missing supplier, a zero amount, or an
     *                           unknown payment method
     */
    public function recordPayment(
        string $supplierUuid,
        string|int|float $amount,
        string $deviceId,
        string $paymentMethod,
        ?string $paymentDate = null,
        ?string $reference = null,
        ?string $notes = null,
        ?string $performedByUserUuid = null,
    ): SupplierPayment {
        $supplier = Supplier::find($supplierUuid);

        if ($supplier === null) {
            throw new SupplierException("supplier '{$supplierUuid}' not found");
        }

        $amount = Money::format($amount);

        if (Money::isZero($amount)) {
            throw new SupplierException('payment amount must not be zero');
        }

        if (! in_array($paymentMethod, config('pharmacy.supplier_payment_methods'), true)) {
            // Suppliers are commonly paid by bank transfer or cheque, unlike POS
            // sales - hence a wider list than the customer payment methods.
            throw new SupplierException("unknown payment method: {$paymentMethod}");
        }

        $payment = SupplierPayment::create([
            'supplier_uuid' => $supplierUuid,
            'amount' => $amount,
            'payment_date' => $paymentDate ?? BusinessDate::today(),
            'payment_method' => $paymentMethod,
            'reference' => $reference,
            'notes' => $notes,
            'performed_by_user_uuid' => $performedByUserUuid,
            'origin_device_id' => $deviceId,
        ]);

        Log::info('supplier payment recorded', [
            'supplier' => $supplier->supplier_name,
            'amount' => $amount,
            'method' => $paymentMethod,
        ]);

        return $payment;
    }

    /** @return Collection<int, SupplierPayment> */
    public function payments(string $supplierUuid): Collection
    {
        return SupplierPayment::query()
            ->where('supplier_uuid', $supplierUuid)
            ->orderByDesc('payment_date')
            ->get();
    }
}
