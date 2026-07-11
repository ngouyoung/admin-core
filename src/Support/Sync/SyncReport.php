<?php

namespace Ngos\AdminCore\Support\Sync;

/**
 * The outcome of a {@see Synchronizer} run — a pure, framework-agnostic data holder shared by every
 * Synchronization Engine (permissions today; menu / routes / policies later).
 *
 * A report is a set of ordered SECTIONS (one per resource, e.g. "Regions"), each holding named,
 * ordered BUCKETS of string items:
 *   - `created`      — rows written this run
 *   - `existing`     — rows that were already present (no-op; idempotency evidence)
 *   - `granted`      — roles the permissions were granted to
 *   - `would-create` / `would-grant` — the dry-run / database-unavailable plan (nothing written)
 *
 * The command decides how to print it; this class only records and answers questions about the run.
 */
final class SyncReport
{
    /** @var array<string, array<string, list<string>>> section => bucket => items */
    private array $sections = [];

    /** True once the run actually wrote to the store (false for --dry-run / report-only). */
    public bool $applied = true;

    /** Register a section so it renders even when it has no items yet. */
    public function ensureSection(string $section): void
    {
        $this->sections[$section] ??= [];
    }

    /** Record one $item under $section's $bucket (order-preserving, de-duplicated). */
    public function record(string $section, string $bucket, string $item): void
    {
        $this->sections[$section] ??= [];
        $this->sections[$section][$bucket] ??= [];
        if (! in_array($item, $this->sections[$section][$bucket], true)) {
            $this->sections[$section][$bucket][] = $item;
        }
    }

    /** @return array<string, array<string, list<string>>> */
    public function sections(): array
    {
        return $this->sections;
    }

    /** Every item recorded under $bucket, across all sections. @return list<string> */
    public function bucket(string $bucket): array
    {
        $out = [];
        foreach ($this->sections as $buckets) {
            foreach ($buckets[$bucket] ?? [] as $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** The section display names, in insertion order. @return list<string> */
    public function sectionNames(): array
    {
        return array_keys($this->sections);
    }
}
