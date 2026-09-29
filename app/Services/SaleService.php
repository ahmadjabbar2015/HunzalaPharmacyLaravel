<?php

namespace App\Services;

use App\Exceptions\SaleException;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\BusinessDate;
use App\Support\DocumentNumber;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * Completing a sale.
 *
 * A sale is written as ONE transaction: the header, its lines, and one negative
 * ledger row per line. They commit together or not at all. If the process dies
 * mid-save, nothing partial survives - because a sale whose stock moved but
 * whose header did not is money taken with no record of taking it, and the
 * reverse is a record of money never taken.
 *
 * Stock is only ever moved through StockService, so two devices selling the same
 * item produce independent ledger rows that both land.
 *
 * The PIN that attributes the sale is collected by the UI at SAVE and passed in
 * as $staffMemberUuid. That is whoever pressed the keys, which is not
 * necessarily whoever is logged in - the drawer and the login are shared, the
 * PIN is not.
 *
 * Ported from shared/services/sale_service.py.
 */
class SaleService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly PresenceService $presence,
    ) {}

    /**
     * Write a completed sale and its stock movements atomically.
     *
     * @param  list<CartLine>  $lines  the cart; must be non-empty, every quantity > 0
     * @param  string|null  $discountType  'fixed' (rupees) or 'percentage', or null
     *
     * @throws SaleException on an empty cart, a non-positive quantity, an unknown
     *                       discount type, or a discount exceeding the subtotal
     */
    public function create(
        array $lines,
        string $staffMemberUuid,
        string $deviceId,
        ?string $sessionUuid = null,
        ?string $customerUuid = null,
        ?string $familyMemberUuid = null,
        ?string $discountType = null,
        string|int|float $discountAmount = 0,
        ?string $discountReason = null,
        bool $discountPinVerified = false,
        string $paymentMethod = 'cash',
        ?string $paymentReference = null,
        bool $madeWhileUnsynced = false,
    ): Sale {
        if ($lines === []) {
            throw new SaleException('cannot save an empty sale');
        }

        foreach ($lines as $line) {
            if ($line->quantity <= 0) {
                // A zero-quantity line is a UI slip, and a negative one would
                // put stock back while charging for it.
                throw new SaleException('every line quantity must be positive');
            }
        }

        $subtotal = Money::sum(array_map(fn (CartLine $line) => $line->amount(), $lines));
        $discount = $this->resolveDiscount($subtotal, $discountType, $discountAmount);
        $net = Money::sub($subtotal, $discount);

        $this->assertWithinItemDiscountLimits($lines, $discount, $discountPinVerified);

        if (Money::isNegative($net)) {
            // Not clamped to zero: an over-subtotal discount is almost always a
            // typo - 500 typed where 50 was meant - and silently giving the
            // stock away is the worse of the two outcomes.
            throw new SaleException('discount cannot exceed the subtotal');
        }

        return DB::transaction(function () use (
            $lines, $staffMemberUuid, $deviceId, $sessionUuid, $customerUuid,
            $familyMemberUuid, $discountType, $discountReason, $discountPinVerified,
            $paymentMethod, $paymentReference, $madeWhileUnsynced,
            $subtotal, $discount, $net
        ) {
            $now = BusinessDate::nowUtc();
            $businessDate = BusinessDate::for($now)->toDateString();

            $sale = Sale::create([
                'invoice_number' => DocumentNumber::invoice($businessDate, $deviceId),
                'customer_uuid' => $customerUuid,
                'family_member_uuid' => $familyMemberUuid,
                // Dated by the Karachi business date, not by UTC: a sale at
                // 00:30 local belongs to that day's takings, and UTC would file
                // it under the previous one.
                'sale_date' => $businessDate,
                'sale_time' => $now,
                'staff_member_uuid' => $staffMemberUuid,
                'session_uuid' => $sessionUuid,
                'subtotal_amount' => $subtotal,
                'discount_type' => $discountType,
                'discount_amount' => $discount,
                'discount_reason' => $discountReason,
                'discount_pin_verified' => $discountPinVerified,
                'net_amount' => $net,
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentReference,
                'made_while_unsynced' => $madeWhileUnsynced,
                'origin_device_id' => $deviceId,
            ]);

            foreach ($lines as $line) {
                SaleItem::create([
                    'sale_uuid' => $sale->uuid,
                    'item_uuid' => $line->itemUuid,
                    'batch_uuid' => $line->batchUuid,
                    'quantity' => $line->quantity,
                    'rate' => $line->rate,
                    'amount' => $line->amount(),
                    'origin_device_id' => $deviceId,
                ]);

                // The stock movement, linked back to this sale so a stock
                // question can always be traced to the transaction that caused
                // it.
                $this->stock->sell(
                    itemUuid: $line->itemUuid,
                    quantity: $line->quantity,
                    deviceId: $deviceId,
                    batchUuid: $line->batchUuid,
                    performedByUserUuid: $staffMemberUuid,
                    sessionUuid: $sessionUuid,
                    referenceUuid: $sale->uuid,
                    referenceType: 'sale',
                );
            }

            $this->presence->ensureCheckedIn($sessionUuid, $staffMemberUuid, $deviceId);

            Log::info('sale completed', [
                'invoice' => $sale->invoice_number,
                'net' => $net,
                'lines' => count($lines),
                'by' => $staffMemberUuid,
            ]);

            return $sale;
        });
    }

    /**
     * The most this cart may be discounted, in rupees.
     *
     * Each item carries its own ceiling as a percentage. The discount here is
     * taken off the whole sale rather than off a line, so the cart's ceiling is
     * the sum of each line's own: a cart of one item capped at 10% and one
     * uncapped item may give away all of the second and a tenth of the first.
     * Spreading a sale-level discount any other way would either punish the
     * uncapped item or let a capped one be discounted past its limit by hiding
     * behind the rest of the basket.
     *
     * Returns null when nothing in the cart is capped, which is the normal case
     * and means "no limit" rather than "a limit of everything".
     *
     * @param  list<CartLine>  $lines
     */
    public function discountCeiling(array $lines): ?string
    {
        $itemUuids = array_values(array_unique(array_map(fn (CartLine $line) => $line->itemUuid, $lines)));

        /** @var array<string, string|null> $limits */
        $limits = Item::query()
            ->whereIn('uuid', $itemUuids)
            ->pluck('max_discount_percent', 'uuid')
            ->all();

        // Nothing in the basket is capped: the sale is governed by the PIN
        // threshold alone, as it was before item limits existed.
        if (array_filter($limits, fn ($percent) => $percent !== null) === []) {
            return null;
        }

        $ceiling = Money::ZERO;

        foreach ($lines as $line) {
            $percent = $limits[$line->itemUuid] ?? null;

            $ceiling = Money::add($ceiling, $percent === null
                // Uncapped: the whole line may be given away.
                ? $line->amount()
                : Money::percentOf($line->amount(), $percent));
        }

        return $ceiling;
    }

    /**
     * Refuse a discount that exceeds what the items in the cart allow.
     *
     * A verified manager PIN overrides it. The limit is a brake on what a
     * counter can do unsupervised, not a rule the owner cannot break - and the
     * override is already audited, so a deliberate one leaves a trail while an
     * accidental one is stopped.
     *
     * @param  list<CartLine>  $lines
     *
     * @throws SaleException when the discount is over the ceiling and unauthorised
     */
    private function assertWithinItemDiscountLimits(array $lines, string $discount, bool $discountPinVerified): void
    {
        if (Money::isZero($discount) || $discountPinVerified) {
            return;
        }

        $ceiling = $this->discountCeiling($lines);

        if ($ceiling === null || ! Money::greaterThan($discount, $ceiling)) {
            return;
        }

        throw new SaleException(
            "discount of {$discount} exceeds the {$ceiling} these items allow; a manager PIN is needed"
        );
    }

    /**
     * Turn a fixed or percentage discount into a rupee figure.
     *
     * Deliberately not clamped at the subtotal. An amount above it overshoots,
     * and create() rejects the sale - see the net < 0 check above.
     *
     * @throws SaleException on an unknown discount type
     */
    private function resolveDiscount(string $subtotal, ?string $discountType, string|int|float $discountAmount): string
    {
        $amount = Money::format($discountAmount);

        // No type, or nothing to take off: not a discount at all. Checked before
        // the type is validated so a stray type with a zero amount is harmless.
        if ($discountType === null || ! Money::greaterThan($amount, Money::ZERO)) {
            return Money::ZERO;
        }

        if ($discountType === 'percentage') {
            return Money::percentOf($subtotal, $amount);
        }

        if ($discountType !== 'fixed') {
            throw new SaleException("unknown discount_type: {$discountType}");
        }

        return $amount;
    }
}
