<?php

/*
 * LARAVEL_PLAN.md §5 asks specifically for "a test asserting a long chain of
 * sale lines still totals exactly". These are those tests.
 *
 * A Unit test, not Feature: no database is involved, and money arithmetic must
 * be provable without one.
 */

use App\Support\Money;

it('adds the classic float failure exactly', function () {
    // 0.1 + 0.2 !== 0.3 in float arithmetic. This is the whole reason the class
    // exists, so it is the first thing asserted.
    expect(Money::add('0.10', '0.20'))->toBe('0.30');
});

it('totals a long cart without drifting', function () {
    // 1000 lines at a price that has no exact binary representation. Summed as
    // floats this lands a few paisa out; the error is small, consistent, and
    // invisible until someone counts the drawer.
    $lines = array_fill(0, 1000, '0.07');

    expect(Money::sum($lines))->toBe('70.00');
});

it('totals an awkward mix of rates exactly', function () {
    $lines = ['19.99', '0.01', '145.55', '0.45', '1000.00', '0.33', '0.67'];

    expect(Money::sum($lines))->toBe('1167.00');
});

it('keeps a running subtraction exact over many steps', function () {
    // A drawer paid down in small amounts must reach exactly zero, not 0.0000001.
    $remaining = '100.00';

    foreach (range(1, 300) as $ignored) {
        $remaining = Money::sub($remaining, '0.33');
    }

    // 300 x 0.33 = 99.00
    expect($remaining)->toBe('1.00');
});

it('rounds a line total once, at the end', function () {
    // 33 units at 19.995. Rounding the rate first gives 33 x 20.00 = 660.00;
    // rounding once at the end gives 659.84. The shelf label says 19.995.
    expect(Money::multiply('19.995', 33))->toBe('659.84');
});

it('computes a percentage without a premature round', function () {
    expect(Money::percentOf('200.00', 10))->toBe('20.00')
        ->and(Money::percentOf('1234.56', '7.5'))->toBe('92.59')
        // 1/3 of a rupee is not representable; it must round, not truncate.
        ->and(Money::percentOf('10.00', '33.333'))->toBe('3.33');
});

it('rounds half a paisa up', function () {
    // HALF_UP is the everyday commercial convention, and what a customer expects
    // when a percentage discount lands exactly between two paisa.
    expect(Money::format('0.125'))->toBe('0.13')
        ->and(Money::format('0.135'))->toBe('0.14')
        // PHP's own round() uses banker's rounding in some configurations and
        // would give 0.12 for the first case.
        ->and(Money::format('2.005'))->toBe('2.01');
});

it('normalises whatever the database hands back', function () {
    // Eloquent's decimal:2 cast returns a string; a migration default may come
    // back as an int; older call sites pass floats. All must agree.
    expect(Money::format('10.5'))->toBe('10.50')
        ->and(Money::format(10))->toBe('10.00')
        ->and(Money::format(10.5))->toBe('10.50')
        ->and(Money::format(null))->toBe('0.00')
        ->and(Money::format(''))->toBe('0.00');
});

it('does not lose paisa on a large float', function () {
    // (string) on a float truncates at PHP's precision setting (14 digits by
    // default), which would drop real paisa off a six-figure total.
    expect(Money::format(1234567.89))->toBe('1234567.89');
});

it('compares amounts by value, not by string', function () {
    expect(Money::equals('10.5', '10.50'))->toBeTrue()
        ->and(Money::equals('10.00', 10))->toBeTrue()
        ->and(Money::equals('10.00', '10.01'))->toBeFalse();
});

it('answers sign and magnitude questions', function () {
    expect(Money::isNegative('-0.01'))->toBeTrue()
        ->and(Money::isNegative('0.00'))->toBeFalse()
        ->and(Money::isZero('0.000'))->toBeTrue()
        ->and(Money::greaterThan('10.01', '10.00'))->toBeTrue()
        ->and(Money::greaterThan('10.00', '10.00'))->toBeFalse();
});

it('caps at the smaller amount', function () {
    // Used to cap a refund at what was actually sold.
    expect(Money::min('50.00', '30.00'))->toBe('30.00')
        ->and(Money::min('30.00', '50.00'))->toBe('30.00')
        ->and(Money::min('30.00', '30.00'))->toBe('30.00');
});

it('sums an empty cart to zero rather than failing', function () {
    expect(Money::sum([]))->toBe('0.00');
});
