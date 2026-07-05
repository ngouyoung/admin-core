<?php

namespace Ngos\AdminCore\Support;

use Illuminate\Database\Eloquent\Model;
use Ngos\AdminCore\Casts\MoneyCast;

/**
 * Aggregates a child relation for a `rollup` field — a document-level total = the sum of its line items
 * (e.g. an invoice total = sum of each line's `line_total`). It's money-aware: {@see Money} values sum
 * exactly via Money (nulls skipped; a mismatched currency makes Money throw), and plain numbers sum
 * numerically. An empty (or all-null) set sums to 0.
 *
 * The relation is summed in memory, so it should be eager-loaded — the generator adds the relation to the
 * list's getData() eager set. For very large child sets, sum a real column with a database aggregate instead.
 */
final class Rollup
{
    /**
     * Sum one attribute across a set of child records. The child value must be consistently one type — all
     * Money or all plain numbers — and money rows must share a currency; an inconsistent set fails loudly
     * (a silently-wrong money total is the worst outcome), rather than dropping rows.
     *
     * @param  iterable<int, object>  $items
     * @param  Model|null  $related  the child model (the relation's ->getRelated()) — lets an EMPTY money
     *                               rollup return a formatted currency zero instead of a bare int 0, since a
     *                               set with no rows has no Money value to infer the type from.
     */
    public static function sum(iterable $items, string $attribute, ?Model $related = null): Money|int|float
    {
        $money = null;
        $numeric = 0;
        $sawNumeric = false;

        foreach ($items as $item) {
            $value = $item->{$attribute} ?? null;
            if ($value === null) {
                continue;
            }
            if ($value instanceof Money) {
                try {
                    $money = $money === null ? $value : $money->add($value);
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException(
                        "Rollup of '{$attribute}': {$e->getMessage()} — a document's lines must share one currency.",
                        0,
                        $e,
                    );
                }
            } else {
                $sawNumeric = true;
                $numeric += (float) $value;
            }
        }

        if ($money !== null && $sawNumeric) {
            throw new \InvalidArgumentException(
                "Rollup of '{$attribute}' mixes Money and plain numbers — the child value must be one type.",
            );
        }

        if ($money !== null) {
            return $money;
        }
        if ($sawNumeric) {
            return $numeric;
        }

        // Empty / all-null: return a money ZERO (formatted, in the column's currency) when the child attribute
        // is money-cast — so a document with no lines shows "$0.00" like every populated sibling, not "0".
        $currency = $related !== null ? self::moneyZeroCurrency($related, $attribute) : false;

        return $currency !== false ? Money::fromMinor(0, $currency === '' ? null : $currency) : $numeric;
    }

    /**
     * When the child model casts $attribute to money, the pinned currency code ('' = config default / a
     * per-record @column, which an empty set can't resolve) — or false when it isn't a money attribute.
     */
    private static function moneyZeroCurrency(Model $related, string $attribute): string|false
    {
        $cast = $related->getCasts()[$attribute] ?? '';
        if (! is_string($cast)) {
            return false;
        }
        [$class, $arg] = array_pad(explode(':', $cast, 2), 2, '');
        if ($class !== MoneyCast::class) {
            return false;
        }

        return str_starts_with($arg, '@') ? '' : $arg; // @currency (per-record) → default; pinned code otherwise
    }
}
