<?php

namespace Ngos\AdminCore\Support\View;

/**
 * RESOURCE-owned view configuration (RFC-0009 Rev 2): the resource's own MULTI-column set of SORTABLE columns —
 * the whitelist an `?sort=col` request may order by (an API controller's `$sortable`).
 *
 * A DISTINCT type from {@see SearchColumnSet} (search and sort are separate concerns and must not be confused),
 * and — like it — a BROAD, multi-column set over the resource's own fields, NOT derived from a single display
 * column. Formalising it must preserve every column the resource sorts by today (a nested set orders by a
 * structural `lft` column, not its label, so narrowing sort to a display column would break it). Immutable;
 * {@see of()} normalises to a de-duplicated, order-preserving list of the declared string column names.
 */
final class SortColumnSet
{
    /** @param  list<string>  $columns */
    private function __construct(public readonly array $columns) {}

    /**
     * Build from a resource's declared sortable columns. De-duplicates, order-preserving, keeping each declared
     * string verbatim; a non-string entry (never a valid column name) is ignored. Membership ({@see contains()})
     * must stay byte-for-byte with the strict `in_array($col, $sortable, true)` whitelist it replaces — so nothing
     * is cast or blank-dropped in a way that could change which `?sort=` values are accepted (a `(string)` cast
     * would let `?sort=0` match a declared int `0`; a blank-drop would reject a declared `''`). A non-string is
     * never strict-equal to the requested string column, so ignoring it cannot change membership.
     *
     * @param  iterable<mixed>  $columns
     */
    public static function of(iterable $columns): self
    {
        $normalized = [];
        foreach ($columns as $column) {
            if (is_string($column) && ! in_array($column, $normalized, true)) {
                $normalized[] = $column;
            }
        }

        return new self($normalized);
    }

    /** @return list<string> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function contains(string $column): bool
    {
        return in_array($column, $this->columns, true);
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    /** Immutable union with another set — only ever WIDENS the sortable set, never narrows it. */
    public function merge(self $other): self
    {
        return self::of([...$this->columns, ...$other->columns]);
    }
}
