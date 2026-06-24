<?php

use Ngos\AdminCore\Rules\DecimalPrecision;

/*
 * DecimalPrecision rejects a number that wouldn't fit a decimal(precision, scale) column — guarding
 * against the silent DB truncation that 'numeric' alone allows.
 */

function decimalFails(mixed $value, int $precision, int $scale): bool
{
    $failed = false;
    (new DecimalPrecision($precision, $scale))->validate('amount', $value, function () use (&$failed) {
        $failed = true;
    });

    return $failed;
}

it('accepts values that fit the precision/scale', function () {
    expect(decimalFails('12345678.1234', 12, 4))->toBeFalse() // 8 int + 4 frac = exactly decimal(12,4)
        ->and(decimalFails('0.5', 12, 4))->toBeFalse()
        ->and(decimalFails('1.50', 12, 4))->toBeFalse()        // trailing zeros don't count
        ->and(decimalFails('-99.99', 4, 2))->toBeFalse()       // sign ignored
        ->and(decimalFails('100', 10, 2))->toBeFalse();
});

it('rejects too many integer or fractional digits', function () {
    expect(decimalFails('123456789', 12, 4))->toBeTrue()  // 9 int > (12-4)
        ->and(decimalFails('1.23456', 12, 4))->toBeTrue() // 5 frac > 4
        ->and(decimalFails('100', 4, 2))->toBeTrue();     // 3 int > (4-2)
});

it('defers null / empty / non-numeric to the other rules (does not fail here)', function () {
    expect(decimalFails(null, 12, 4))->toBeFalse()
        ->and(decimalFails('', 12, 4))->toBeFalse()
        ->and(decimalFails('abc', 12, 4))->toBeFalse(); // 'numeric' reports the type error
});

it('expands scientific notation before counting digits', function () {
    expect(decimalFails('1.5e3', 12, 4))->toBeFalse() // 1500 → fits
        ->and(decimalFails('1e13', 12, 4))->toBeTrue(); // 14 integer digits → too big
});
