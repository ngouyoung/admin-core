<?php

namespace Ngos\AdminCore\Support\View;

use Illuminate\Database\Eloquent\Model;

/**
 * RESOURCE-owned view configuration (RFC-0009 Rev 2): the single column used to display / sort / search ONE
 * relation by its label. Its default derives from the RELATED model's canonical label backing column
 * (via {@see ac_display_descriptor()}) — a real `column` when the related model has one, or NONE when that
 * label is `computed` (a model accessor with no single backing column) or absent (`none`).
 *
 * A relation whose display column is NONE is, formally, not sortable/searchable by a label column — there is no
 * column to ORDER BY / LIKE against, and (per the RFC) never a route-key fallback for the SQL side. This value
 * object only DESCRIBES that fact through {@see column()} / {@see isNone()} / {@see sortable()} / {@see searchable()};
 * it does NOT act on it. Today's rendered label is preserved byte-for-byte by {@see labelColumn()}, which keeps the
 * legacy route-key fallback exactly as {@see ac_display_column()} does — acting on NONE is a later, gated change.
 *
 * Immutable + stateless. Resolved from the model TYPE via the memoised descriptor (composition fidelity — never a
 * mutable, possibly-aliased instance getTable()).
 */
final class RelationDisplayColumn
{
    private function __construct(
        // The related label's descriptor kind: 'column' | 'computed' | 'none'.
        public readonly string $kind,
        // The descriptor's backing column: set for 'column' AND 'computed' (the accessor name), null for 'none'.
        public readonly ?string $backingColumn,
        // The related model's route key — the legacy display fallback used when there is no backing column.
        public readonly string $routeKey,
    ) {}

    /** Resolve the relation display column for a related model, from its (memoised, type-resolved) descriptor. */
    public static function for(Model $related): self
    {
        $descriptor = ac_display_descriptor($related);

        return new self($descriptor['kind'], $descriptor['column'], $related->getRouteKeyName());
    }

    /**
     * The FORMAL display column: a real backing column, or NONE (null) when the related label is computed or
     * absent. This is the label sort/search target — NONE means the relation has no label column to order/filter
     * by (a computed accessor is not a real column, so it is correctly NONE here, not the accessor name).
     */
    public function column(): ?string
    {
        return $this->kind === 'column' ? $this->backingColumn : null;
    }

    /** NONE — the related label resolves to no single backing column (it is computed or absent). */
    public function isNone(): bool
    {
        return $this->column() === null;
    }

    /** Whether this relation can be sorted by its label column (only when it has a real backing column). */
    public function sortable(): bool
    {
        return ! $this->isNone();
    }

    /** Whether this relation can be searched by its label column (only when it has a real backing column). */
    public function searchable(): bool
    {
        return ! $this->isNone();
    }

    /**
     * The BACKWARD-COMPATIBLE display column — byte-for-byte identical to {@see ac_display_column()}: the
     * descriptor's backing column (the real column, or a computed label's accessor name), or the route key when
     * there is none. Consumers that must preserve today's rendered label (including the route-key fallback for a
     * label-less related model) read THIS, not {@see column()}.
     */
    public function labelColumn(): string
    {
        return $this->backingColumn ?? $this->routeKey;
    }
}
