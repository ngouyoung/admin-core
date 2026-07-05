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

it('resolves a per-record currency column from the request (@column), like the cast', function () {
    // The rule reads the sibling currency the form posts, matching MoneyCast's per-record resolution.
    request()->merge(['currency' => 'PTS']);
    expect(moneyFails(new MoneyAmount('@currency'), '99999999999999'))->toBeTrue(); // PTS → too large

    request()->replace(['currency' => 'USD']);
    expect(moneyFails(new MoneyAmount('@currency'), '99999999999999'))->toBeFalse(); // USD → fits
});
