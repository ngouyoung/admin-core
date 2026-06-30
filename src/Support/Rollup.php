<?php

namespace Ngos\AdminCore\Support;

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
     */
    public static function sum(iterable $items, string $attribute): Money|int|float
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

        return $money ?? $numeric;
    }
}
