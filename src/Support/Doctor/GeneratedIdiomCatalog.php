<?php

namespace Ngos\AdminCore\Support\Doctor;

/**
 * The catalog of SUPERSEDED framework idioms that `admin-core:make` once emitted into a host app's
 * per-resource files, keyed to the release that replaced each idiom and the remedy for a stale copy.
 *
 * WHY THIS EXISTS. `admin-core:doctor` byte-compares only the frozen frontend kit (JS/SCSS/theme views).
 * It never inspected the generated controllers/services/models/FormRequests/views — yet the CHANGELOG
 * directs operators to doctor for exactly those files after a generated-code fix. A byte-compare is
 * impossible here: the per-resource stubs carry `DummyClass` / `__AC_*__` placeholders, so a generated
 * file is never byte-identical to its stub. Instead doctor idiom-LINTS a generated file for known,
 * superseded constructs — a construct the CURRENT generator provably no longer emits (so an up-to-date
 * resource never matches), matched WITHIN the generated construct to bound false positives.
 *
 * MECHANISM, NOT POLICY. Every entry keys off a framework-owned generated idiom (a search helper, a
 * route-key accessor, a trash view) — never off what a resource MEANS. No domain vocabulary appears here;
 * a `framework-boundary` test asserts it.
 *
 * APPEND-ONLY. Once an idiom is catalogued it stays (superseded idioms accrete across releases); a future
 * full-fidelity manifest diff (CG-1) subsumes this interim idiom-lint without dropping the history.
 *
 * SEVERITY drives doctor's exit code: only `security`/`correctness` staleness reddens the build; `cosmetic`
 * staleness is reported but advisory (the operator decides). See {@see self::ACTIONABLE}.
 */
final class GeneratedIdiomCatalog
{
    /** Severities that make doctor exit non-zero (a shipped security/correctness fix sits unapplied). */
    public const ACTIONABLE = ['security', 'correctness'];

    /**
     * @return list<array{id: string, appliesTo: string, severity: string, supersededIn: string, description: string, signature: string, remedy: string}>
     *   appliesTo — the generated file kind the signature is scoped to ({@see self::fileKinds()}).
     *   signature — a PCRE the SUPERSEDED idiom matches and current generator output does NOT.
     */
    public static function entries(): array
    {
        return [
            [
                'id' => 'raw-like-filtercolumn',
                'appliesTo' => 'backend-controller',
                'severity' => 'security',
                'supersededIn' => 'v2.79.152',
                'description' => "getData() filterColumn LIKE-matches the datatables keyword unescaped (raw ->where(…, 'like', \"%\$keyword%\")) instead of routing through Support\\Search — a wildcard over-match",
                // The pre-v2.79.152 idiom: a raw `'like'` operator whose %-wrapped bind interpolates the generated
                // filterColumn closure's `$keyword` — matches the ->where('col', 'like', "%{$keyword}%") (foreign)
                // AND the ->orWhere('col->'.$locale, 'like', '%'.$keyword.'%') (translatable) forms. Scoping to the
                // generated `$keyword` var (not a bare `'like','%'`) keeps a developer's OWN hand-written LIKE — a
                // literal, or one over a differently-named term — from tripping the check (contract §2.1: match
                // within the generated construct). Current output routes through Search::whereLike/whereJsonLike
                // (the string 'like' never appears), so an up-to-date controller cannot match either way.
                'signature' => '/[\'"]like[\'"]\s*,\s*[\'"]%[^)]{0,40}\$keyword/',
                'remedy' => "regenerate (admin-core:make <Name> --force) or replace the raw ->where(…, 'like', …) with \\Ngos\\AdminCore\\Support\\Search::whereLike(\$q, 'column', \$keyword)",
            ],
            [
                'id' => 'trash-route-key-id',
                'appliesTo' => 'trash-view',
                'severity' => 'correctness',
                'supersededIn' => 'v2.79.27',
                'description' => 'trash view keys a restore/forceDelete route param OR the bulk-select checkbox value off $item->id instead of $item->getRouteKey() — restore/force-delete 404s on a --uuid (hybrid-key) resource',
                // Covers ALL three pre-v2.79.27 sites the fix touched: the restore route param, the forceDelete route
                // param, and the row-check checkbox `value="{{ $item->id }}"` (the last drives bulk restore/force —
                // a partial hand-patch that fixes only the routes leaves it silently broken). NOT a bare `$item->id`:
                // the current trash view still uses it as a LABEL fallback (`ac_related_label($item) ?: $item->id`),
                // which must not match. The current stub uses $item->getRouteKey() at all three real sites.
                'signature' => '/route\([^)]*(?:restore|forceDelete)[^)]*\$item->id\b|value="\{\{\s*\$item->id\s*\}\}"/',
                'remedy' => "regenerate the resource or replace \$item->id with \$item->getRouteKey() at the trash view's restore/forceDelete route params and the bulk-select checkbox value",
            ],
        ];
    }

    /**
     * Every catalogued idiom whose signature matches this file's contents, given the file kind.
     *
     * @return list<array{id: string, appliesTo: string, severity: string, supersededIn: string, description: string, signature: string, remedy: string}>
     */
    public static function matches(string $fileKind, string $contents): array
    {
        return array_values(array_filter(
            self::entries(),
            fn (array $e) => $e['appliesTo'] === $fileKind && preg_match($e['signature'], $contents) === 1,
        ));
    }

    /** The distinct generated file kinds the catalog scopes to — the doctor scan maps each to a host path set. */
    public static function fileKinds(): array
    {
        return array_values(array_unique(array_map(fn (array $e) => $e['appliesTo'], self::entries())));
    }

    /** True when this severity should make doctor exit non-zero (the severity-gate). */
    public static function isActionable(string $severity): bool
    {
        return in_array($severity, self::ACTIONABLE, true);
    }
}
