<?php

use Ngos\AdminCore\Rules\MoneyAmount;

/*
 * MoneyAmount validation rule: accepts an amount iff Money::fromMajor() will store it for the resolved
 * currency — so the storable bound tracks the currency's decimals instead of a fixed 4-decimal assumption.
 */

beforeEach(function () {
    config()->set('admin-core.money.currency', 'USD');
    config()->set('admin-core.money.currencies', [
        'USD' => ['symbol' => '$', 'decimals' => 2],
        'KHR' => ['symbol' => '៛', 'decimals' => 0],
        'PTS' => ['symbol' => 'pts', 'decimals' => 6], // a >4-decimal unit — the finding's case
    ]);
});

/** Run the rule and capture whether it failed. */
function moneyFails(MoneyAmount $rule, mixed $value): bool
{
    $failed = false;
    $rule->validate('amount', $value, function () use (&$failed) { $failed = true; });

    return $failed;
}

it('defers null / empty / non-numeric to the other rules', function () {
    expect(moneyFails(new MoneyAmount, null))->toBeFalse()
        ->and(moneyFails(new MoneyAmount, ''))->toBeFalse()
        ->and(moneyFails(new MoneyAmount, 'abc'))->toBeFalse();
});

it('rejects a non-finite amount (1e400 → INF) that numeric would accept', function () {
    expect(moneyFails(new MoneyAmount, '1e400'))->toBeTrue();
});

it('bounds the amount to the currency decimals — a 6-decimal currency rejects what a 2-decimal one allows', function () {
    // 14 nines: fits USD (2 decimals → 16 minor-unit int digits, ≤18) but NOT PTS (6 decimals → 20 digits).
    $big = '99999999999999';

    expect(moneyFails(new MoneyAmount('USD'), $big))->toBeFalse()  // 2 decimals → stores fine
        ->and(moneyFails(new MoneyAmount('PTS'), $big))->toBeTrue() // 6 decimals → would 500 in fromMajor
        ->and(moneyFails(new MoneyAmount('PTS'), '999999'))->toBeFalse(); // an in-range PTS amount passes

    // KHR (0 decimals) tolerates the most integer digits.
    expect(moneyFails(new MoneyAmount('KHR'), '999999999999999999'))->toBeFalse(); // 18 digits, stores
});

it('resolves a per-record currency column from the DATA under validation (@column), like the cast', function () {
    // The rule reads the sibling currency from the data being validated (the form row OR the CSV import row) via
    // DataAwareRule::setData — matching MoneyCast's per-record resolution. NOT request(), which is empty on import.
    expect(moneyFails((new MoneyAmount('@currency'))->setData(['currency' => 'PTS']), '99999999999999'))->toBeTrue()   // PTS → too large
        ->and(moneyFails((new MoneyAmount('@currency'))->setData(['currency' => 'USD']), '99999999999999'))->toBeFalse(); // USD → fits
});

it('resolves @currency during a CSV import (Validator on the row, request() empty) — no false pass / 500', function () {
    // WebController::import() runs Validator::make($row, $rules), which calls setData($row). The '@currency' must
    // resolve from the ROW, not request() (only the uploaded file). Before the fix it fell back to the default
    // USD and let a PTS(6dp)-overflow value pass, then threw inside the cast at create() as an uncaught 500.
    $v = \Illuminate\Support\Facades\Validator::make(
        ['currency' => 'PTS', 'total' => '99999999999999'],       // an import row: fits USD, overflows PTS
        ['total' => ['numeric', new MoneyAmount('@currency')]],
    );

    expect($v->fails())->toBeTrue()
        ->and($v->errors()->first('total'))->toContain('too large');
});
