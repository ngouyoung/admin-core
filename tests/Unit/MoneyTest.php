<?php

use Ngos\AdminCore\Support\Money;

/* The Money value object: exact minor-unit storage + currency-aware parse/format. */

beforeEach(function () {
    config()->set('admin-core.money.currency', 'USD');
    config()->set('admin-core.money.currencies', [
        'USD' => ['symbol' => '$', 'decimals' => 2, 'position' => 'before', 'thousands' => ',', 'decimal' => '.'],
        'KHR' => ['symbol' => '៛', 'decimals' => 0, 'position' => 'before', 'thousands' => ',', 'decimal' => '.'],
        'EUR' => ['symbol' => '€', 'decimals' => 2, 'position' => 'after', 'thousands' => '.', 'decimal' => ','],
    ]);
});

// -- Parsing major -> exact minor units --------------------------------------------------------------

it('parses a major amount to minor units using the currency decimals', function () {
    expect(Money::fromMajor('15.00', 'USD')->minor)->toBe(1500)
        ->and(Money::fromMajor('15.5', 'USD')->minor)->toBe(1550)
        ->and(Money::fromMajor(0.05, 'USD')->minor)->toBe(5)
        ->and(Money::fromMajor('1,234.50', 'USD')->minor)->toBe(123450) // grouping stripped
        ->and(Money::fromMajor('$15', 'USD')->minor)->toBe(1500);       // symbol stripped
});

it('treats a 0-decimal currency (KHR) as 1:1 — no multiply by 100', function () {
    expect(Money::fromMajor('15000', 'KHR')->minor)->toBe(15000)
        ->and(Money::fromMajor('15,000', 'KHR')->minor)->toBe(15000);
});

it('rounds a too-precise input to the currency scale', function () {
    expect(Money::fromMajor('15.999', 'USD')->minor)->toBe(1600); // 15.999 -> 16.00
});

it('rounds exactly in integer space — no binary-float artefacts', function () {
    // 1.005 * 100 is 100.4999… as a double; a float parse would store 100. Integer parsing keeps it 101.
    expect(Money::fromMajor('1.005', 'USD')->minor)->toBe(101)
        ->and(Money::fromMajor('0.285', 'USD')->minor)->toBe(29)
        ->and(Money::fromMajor(1.005, 'USD')->minor)->toBe(101)   // a float argument too
        ->and(Money::fromMajor('0.994', 'USD')->minor)->toBe(99)  // rounds down
        ->and(Money::fromMajor('0.999', 'USD')->minor)->toBe(100); // carry across the decimal point
});

it('parses negative amounts (refunds / adjustments), including a sign after a symbol', function () {
    expect(Money::fromMajor('-5.05', 'USD')->minor)->toBe(-505)
        ->and(Money::fromMajor('$-5', 'USD')->minor)->toBe(-500)  // minus after the symbol still negates
        ->and(Money::fromMajor('-$5', 'USD')->minor)->toBe(-500);
});

it('expands scientific notation a number input may submit', function () {
    expect(Money::fromMajor('1e3', 'USD')->minor)->toBe(100000)   // 1e3 = 1000.00
        ->and(Money::fromMajor('1.5e2', 'USD')->minor)->toBe(15000);
});

it('strips a thousands separator (dot-decimal contract)', function () {
    expect(Money::fromMajor('1,234.50', 'USD')->minor)->toBe(123450);
});

// -- major(): exact integer -> decimal string (no float) ---------------------------------------------

it('renders an exact plain major string', function () {
    expect(Money::fromMinor(1500, 'USD')->major())->toBe('15.00')
        ->and(Money::fromMinor(5, 'USD')->major())->toBe('0.05')
        ->and(Money::fromMinor(-505, 'USD')->major())->toBe('-5.05')
        ->and(Money::fromMinor(0, 'USD')->major())->toBe('0.00')
        ->and(Money::fromMinor(15000, 'KHR')->major())->toBe('15000'); // 0 decimals -> no point
});

// -- format(): symbol + grouping ---------------------------------------------------------------------

it('formats with the symbol before and grouped thousands (USD)', function () {
    expect((string) Money::fromMinor(1500, 'USD'))->toBe('$15.00')
        ->and((string) Money::fromMinor(123450, 'USD'))->toBe('$1,234.50')
        ->and((string) Money::fromMinor(-505, 'USD'))->toBe('-$5.05');
});

it('formats KHR with the riel symbol and no decimals', function () {
    expect((string) Money::fromMinor(15000, 'KHR'))->toBe('៛15,000');
});

it('honours symbol-after position and locale separators (EUR)', function () {
    expect((string) Money::fromMinor(123450, 'EUR'))->toBe('1.234,50€');
});

it('falls back to the code as symbol with 2 decimals for an unconfigured currency', function () {
    expect((string) Money::fromMinor(1500, 'XAF'))->toBe('XAF15.00');
});

// -- arithmetic stays exact (the whole point) --------------------------------------------------------

it('adds and subtracts exactly, with no float drift', function () {
    $sum = Money::fromMajor('0.10', 'USD')->add(Money::fromMajor('0.20', 'USD'));
    expect($sum->minor)->toBe(30)->and($sum->major())->toBe('0.30'); // 0.1 + 0.2 === 0.30, exactly

    expect(Money::fromMinor(1000, 'USD')->subtract(Money::fromMinor(250, 'USD'))->minor)->toBe(750);
});

it('multiplies (price x quantity) and rounds back to whole minor units', function () {
    expect(Money::fromMinor(199, 'USD')->multiply(3)->minor)->toBe(597)      // $1.99 x 3 = $5.97
        ->and(Money::fromMinor(100, 'USD')->multiply(0.125)->minor)->toBe(13) // rounds 12.5 -> 13
        ->and(Money::fromMinor(300, 'USD')->multiply('2.500')->minor)->toBe(750); // a decimal-cast string operand
});

it('divides and rounds back to whole minor units (incl. a string divisor)', function () {
    expect(Money::fromMinor(1000, 'USD')->divide(4)->minor)->toBe(250)
        ->and(Money::fromMinor(1000, 'USD')->divide('3')->minor)->toBe(333); // 333.33 -> 333
});

it('treats a null operand as null, not a crash (so a computed total over a nullable column is blank)', function () {
    expect(Money::fromMinor(1000, 'USD')->add(null))->toBeNull()
        ->and(Money::fromMinor(1000, 'USD')->subtract(null))->toBeNull()
        ->and(Money::fromMinor(1000, 'USD')->multiply(null))->toBeNull()
        ->and(Money::fromMinor(1000, 'USD')->divide(null))->toBeNull();
});

it('refuses to combine two different currencies', function () {
    Money::fromMinor(100, 'USD')->add(Money::fromMinor(100, 'KHR'));
})->throws(InvalidArgumentException::class);

// -- predicates + serialisation ----------------------------------------------------------------------

it('reports zero and negative', function () {
    expect(Money::fromMinor(0, 'USD')->isZero())->toBeTrue()
        ->and(Money::fromMinor(-1, 'USD')->isNegative())->toBeTrue()
        ->and(Money::fromMinor(1, 'USD')->isNegative())->toBeFalse();
});

it('serialises to a structured array for the API', function () {
    expect(Money::fromMinor(1500, 'USD')->toArray())->toBe([
        'minor' => 1500,
        'major' => '15.00',
        'currency' => 'USD',
        'formatted' => '$15.00',
    ])->and(json_decode(json_encode(Money::fromMinor(1500, 'USD')), true)['formatted'])->toBe('$15.00');
});

it('uses the configured default currency when none is given', function () {
    config()->set('admin-core.money.currency', 'KHR');
    expect(Money::fromMajor('15000')->currency)->toBe('KHR')
        ->and((string) Money::fromMinor(15000))->toBe('៛15,000');
});
