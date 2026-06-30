<?php

namespace Ngos\AdminCore\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Ngos\AdminCore\Support\Money;

/**
 * Casts an integer (minor-unit) column to a {@see Money} value object and back. Declared on the model:
 *
 *   'price' => MoneyCast::class              // currency from config('admin-core.money.currency')
 *   'price' => MoneyCast::class.':KHR'       // pin this column to a specific currency
 *   'price' => MoneyCast::class.':@currency' // PER-RECORD: read the code from this row's `currency` column
 *
 * Reading gives a Money object ($model->price->format() === '$15.00'); writing accepts a Money object or a
 * plain MAJOR amount from a form/import ("15.00", 15.5) and stores the exact minor-unit integer (1500).
 *
 * Per-record currency lets one column hold amounts in different currencies row-by-row (e.g. a Purchase in USD
 * next to one in KHR), reading the code from a sibling column. A read is correct as long as the whole row is
 * loaded — don't `select()` away the currency column, or it falls back to the default. A write needs the
 * currency column to be set first — declare it before the money column so the generated form/rules fill it
 * first (the make command warns otherwise). If it's missing at write time the configured default currency is
 * used as a best-effort fallback.
 *
 * @implements CastsAttributes<Money|null, Money|int|float|string|bool|null>
 */
class MoneyCast implements CastsAttributes
{
    /** A pinned ISO code ("KHR"), or null when the currency comes from config or a per-record column. */
    protected ?string $currency = null;

    /** A sibling column the per-record currency code is read from ("currency"), or null. */
    protected ?string $currencyColumn = null;

    public function __construct(?string $currency = null)
    {
        if ($currency !== null && str_starts_with($currency, '@')) {
            $this->currencyColumn = substr($currency, 1);
        } else {
            $this->currency = $currency;
        }
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::fromMinor((int) $value, $this->currencyFor($attributes));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $currency = $this->currencyFor($attributes);

        // A Money object carries its own exact minor units. If we know this row's currency, refuse a Money of a
        // different one — storing its minor units would silently reinterpret cents as a new unit (e.g. a USD
        // $15.00 = 1500 written to a KHR column would read back as ៛1,500). Mirrors Money::add().
        if ($value instanceof Money) {
            if ($currency !== null && strtoupper($currency) !== $value->currency) {
                throw new InvalidArgumentException(
                    "Refusing to store a {$value->currency} amount in a column resolved to "
                    . strtoupper($currency) . '. Convert it first.',
                );
            }

            return $value->minor;
        }

        // A scalar is a human/major amount ("15.00") — parse it to exact minor units for this row's currency.
        return Money::fromMajor($value, $currency)->minor;
    }

    /**
     * This row's currency: a per-record sibling column (read from the RAW attributes, so an enum-cast currency
     * column gives its backing code "USD" not the enum object), a pinned code, or null (the configured default).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function currencyFor(array $attributes): ?string
    {
        if ($this->currencyColumn === null) {
            return $this->currency;
        }

        $code = $attributes[$this->currencyColumn] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}
