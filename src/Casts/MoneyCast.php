<?php

namespace Ngos\AdminCore\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Ngos\AdminCore\Support\Money;

/**
 * Casts an integer (minor-unit) column to a {@see Money} value object and back. Declared on the model:
 *
 *   'price' => MoneyCast::class             // currency from config('admin-core.money.currency')
 *   'price' => MoneyCast::class.':KHR'      // pin this column to a specific currency
 *
 * Reading gives a Money object ($model->price->format() === '$15.00'); writing accepts a Money object or a
 * plain MAJOR amount from a form/import ("15.00", 15.5) and stores the exact minor-unit integer (1500).
 *
 * @implements CastsAttributes<Money|null, Money|int|float|string|bool|null>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(protected ?string $currency = null) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::fromMinor((int) $value, $this->currency);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        // A Money object carries its own exact minor units. If the column is pinned to a currency, refuse a
        // Money of a different one — storing its minor units would silently reinterpret cents as a new unit
        // (e.g. a USD $15.00 = 1500 written to a KHR column would read back as ៛1,500). Mirrors Money::add().
        if ($value instanceof Money) {
            if ($this->currency !== null && strtoupper($this->currency) !== $value->currency) {
                throw new InvalidArgumentException(
                    "Refusing to store a {$value->currency} amount in a column pinned to "
                    . strtoupper($this->currency) . '. Convert it first.',
                );
            }

            return $value->minor;
        }

        // A scalar is a human/major amount ("15.00") — parse it to exact minor units for the column's currency.
        return Money::fromMajor($value, $this->currency)->minor;
    }
}
