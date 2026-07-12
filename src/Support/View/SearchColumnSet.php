<?php

namespace Ngos\AdminCore\Support\View;

/**
 * RESOURCE-owned view configuration (RFC-0009 Rev 2): the resource's own MULTI-column set of SEARCHABLE columns —
 * the columns an OR-LIKE keyword search fans out across (an API controller's `$searchable`, a WebController's
 * `$selectSearch`).
 *
 * Deliberately a BROAD, multi-column set over the resource's own fields — it is NOT derived from, nor narrowed to,
 * a single display column. The split from {@see RelationDisplayColumn} exists precisely to prevent silent search
 * narrowing: formalising the set must preserve every column the resource searches today. Immutable; {@see of()}
 * normalises to a de-duplicated, order-preserving list of the declared string column names, so the resulting
 * search is byte-for-byte the resource's current behaviour.
 */
final class SearchColumnSet
{
    /** @param  list<string>  $columns */
    private function __construct(public readonly array $columns) {}

    /**
     * Build from a resource's declared searchable columns. De-duplicates, order-preserving, keeping each declared
     * string verbatim; a non-string entry (never a valid column name) is ignored. Only a redundant duplicate is
     * removed — which cannot change which rows an OR-LIKE matches (`A OR A ≡ A`) — so a search built from
     * {@see columns()} matches exactly what the resource matches today. Membership ({@see contains()}) likewise
     * stays byte-for-byte with a strict `in_array()` whitelist: nothing is cast or blank-dropped in a way that
     * changes membership (kept identical to {@see SortColumnSet} so the two sets normalise the same way).
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

    /** Immutable union with another set — only ever WIDENS the searchable set, never narrows it. */
    public function merge(self $other): self
    {
        return self::of([...$this->columns, ...$other->columns]);
    }
}
