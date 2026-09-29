<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/*
 * Every money calculation in the system goes through here.
 *
 * LARAVEL_PLAN.md §5's first named trap. PHP has no decimal type: 0.1 + 0.2 is
 * not 0.3, and in a pharmacy that becomes a till four rupees out with no
 * explanation. Worse, Eloquent's `decimal:2` cast returns a STRING, and
 * arithmetic on a string silently promotes it to float - so the unsafe path is
 * also the one that looks most natural:
 *
 *     $net = $sale->subtotal_amount - $sale->discount_amount;   // float. wrong.
 *     $net = Money::sub($sale->subtotal_amount, $sale->discount_amount);  // exact.
 *
 * Everything here returns a SCALE-2 STRING, which is what the decimal columns
 * store and what Eloquent hands back. Strings in, strings out, no float ever
 * holds a rupee value in between.
 *
 * Rounding is HALF_UP, the everyday commercial convention and what a customer
 * expects when a percentage discount lands on half a paisa. It is stated once
 * here rather than passed at each call site, so two calculations can never
 * disagree about it.
 */
class Money
{
    /** Rupee amounts carry two decimal places: paisa. */
    public const SCALE = 2;

    public const ZERO = '0.00';

    /**
     * Parse any accepted money input into an exact decimal.
     *
     * A float argument is accepted because form input and older call sites
     * produce them, and is converted through its decimal string form rather
     * than its binary value. That makes 0.1 exactly 0.1 rather than
     * 0.1000000000000000055511151231257827, but it cannot rescue precision a
     * float has already lost - so prefer passing strings.
     */
    public static function of(string|int|float|BigDecimal|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }

        if ($value instanceof BigDecimal) {
            return $value;
        }

        if (is_float($value)) {
            // var_export, not (string), because PHP's default float-to-string
            // precision truncates at 14 digits and would lose real paisa on a
            // large total.
            return BigDecimal::of(var_export($value, true));
        }

        return BigDecimal::of($value);
    }

    /** Normalise to the scale-2 string the decimal columns store. */
    public static function format(string|int|float|BigDecimal|null $value): string
    {
        return (string) self::of($value)->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    public static function add(string|int|float|BigDecimal $a, string|int|float|BigDecimal $b): string
    {
        return self::format(self::of($a)->plus(self::of($b)));
    }

    public static function sub(string|int|float|BigDecimal $a, string|int|float|BigDecimal $b): string
    {
        return self::format(self::of($a)->minus(self::of($b)));
    }

    /**
     * A rate times a quantity.
     *
     * Rounded once, at the end. Rounding a rate before multiplying is how a
     * 33-unit line ends up a rupee off the price on the shelf label.
     */
    public static function multiply(string|int|float|BigDecimal $rate, string|int|float|BigDecimal $quantity): string
    {
        return self::format(self::of($rate)->multipliedBy(self::of($quantity)));
    }

    /**
     * Sum any number of amounts exactly.
     *
     * Accumulates at full precision and rounds once at the end, so a long cart
     * cannot drift by a paisa per line. This is the case LARAVEL_PLAN.md §5
     * asks for a test on.
     *
     * @param  iterable<string|int|float|BigDecimal>  $amounts
     */
    public static function sum(iterable $amounts): string
    {
        $total = BigDecimal::zero();

        foreach ($amounts as $amount) {
            $total = $total->plus(self::of($amount));
        }

        return self::format($total);
    }

    /**
     * $percent per cent of $amount.
     *
     * Divided at scale 4 before rounding to 2, so 7.5% of an odd subtotal does
     * not lose a paisa to a premature round.
     */
    public static function percentOf(string|int|float|BigDecimal $amount, string|int|float|BigDecimal $percent): string
    {
        $result = self::of($amount)
            ->multipliedBy(self::of($percent))
            ->dividedBy(100, self::SCALE + 2, RoundingMode::HalfUp);

        return self::format($result);
    }

    /** True when $a and $b are the same amount, whatever their scale or type. */
    public static function equals(string|int|float|BigDecimal $a, string|int|float|BigDecimal $b): bool
    {
        return self::of($a)->toScale(self::SCALE, RoundingMode::HalfUp)
            ->isEqualTo(self::of($b)->toScale(self::SCALE, RoundingMode::HalfUp));
    }

    public static function isNegative(string|int|float|BigDecimal $value): bool
    {
        return self::of($value)->isNegative();
    }

    public static function isZero(string|int|float|BigDecimal $value): bool
    {
        return self::of($value)->isZero();
    }

    /** True when $a is strictly greater than $b. */
    public static function greaterThan(string|int|float|BigDecimal $a, string|int|float|BigDecimal $b): bool
    {
        return self::of($a)->isGreaterThan(self::of($b));
    }

    /**
     * $amount split $divisor ways.
     *
     * Divided at scale 4 before rounding to 2, for the same reason percentOf
     * does: a pack of 30 at 250.00 is 8.33 a tablet, and rounding the division
     * early would put the shelf price a paisa out on every strip.
     *
     * A zero or negative divisor returns the amount unchanged rather than
     * throwing: pack_size is validated at >= 1 everywhere it is written, and a
     * price screen is not the place to surface a division error.
     */
    public static function divide(string|int|float|BigDecimal $amount, string|int|float|BigDecimal $divisor): string
    {
        $by = self::of($divisor);

        if ($by->isLessThanOrEqualTo(0)) {
            return self::format($amount);
        }

        return self::format(self::of($amount)->dividedBy($by, self::SCALE + 2, RoundingMode::HalfUp));
    }

    /** The smaller of two amounts - used to cap a refund at what was sold. */
    public static function min(string|int|float|BigDecimal $a, string|int|float|BigDecimal $b): string
    {
        return self::format(self::greaterThan($a, $b) ? $b : $a);
    }
}
