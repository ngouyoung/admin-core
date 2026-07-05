<?php

namespace Ngos\AdminCore\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Ngos\AdminCore\Support\Money;

/**
 * Reject a money amount the {@see Money}/MoneyCast can't store for its currency. A money column stores minor
 * units in a bigint (18 storable digits), so the largest MAJOR amount depends on the currency's decimals:
 * a 2-decimal currency holds up to 16 integer digits, a 6-decimal one only 12. The old fixed
 * `between:-99999999999999,99999999999999` rule assumed 4 decimals — so a >4-decimal currency (e.g. a
 * 6-decimal points/crypto unit) let an in-range-looking value pass validation, then threw inside
 * Money::fromMajor() at save time as an uncaught 500.
 *
 * This rule resolves the SAME currency the cast will (a pinned code, a per-record currency column read from
 * the request, or the configured default) and accepts the value iff Money::fromMajor() accepts it — so the
 * bound is always exactly right for the actual currency, decimals and all. Lives in src/ so a fix reaches
 * every install via the package, not a frozen stub. Pair it with the `numeric` rule.
 *
 * The generator emits `new MoneyAmount('KHR')` (pinned), `new MoneyAmount('@currency')` (per-record column)
 * or `new MoneyAmount()` (config default) — the same argument shape as MoneyCast.
 */
class MoneyAmount implements ValidationRule
{
    /** A pinned ISO code ("KHR"), a per-record column marked "@currency", or null for the config default. */
    public function __construct(private ?string $currency = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return; // 'nullable' / 'numeric' own these cases
        }

        // 1e400 → INF, which is_numeric() accepts but no column can store.
        if (! is_finite((float) $value)) {
            $fail('The :attribute is out of range.');

            return;
        }

        try {
            // Delegate to the exact parse the cast performs on save — same currency resolution, same
            // storable-range guard — so validation passes iff the value will actually store.
            Money::fromMajor((string) $value, $this->resolveCurrency());
        } catch (InvalidArgumentException) {
            $fail('The :attribute is too large to store.');
        }
    }

    /** The currency the cast will use: a per-record request column ("@currency"), a pinned code, or default. */
    private function resolveCurrency(): ?string
    {
        if ($this->currency !== null && str_starts_with($this->currency, '@')) {
            $posted = request()->input(substr($this->currency, 1));

            return is_string($posted) && $posted !== '' ? $posted : null;
        }

        return $this->currency;
    }
}
