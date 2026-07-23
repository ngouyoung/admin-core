<?php

namespace Ngos\AdminCore\Support;

class Search
{
    /**
     * The LIKE ESCAPE sentinel — the character that makes a `%` or `_` in the user's term match LITERALLY.
     * Deliberately NOT backslash: MySQL/MariaDB process C-style backslash escapes in string literals, so
     * `ESCAPE '\'` is an unterminated string (ERROR 1064), and no single SQL literal spells one backslash on
     * both MySQL and PostgreSQL. `!` is inert in string literals AND in LIKE patterns on every supported
     * driver (SQLite, MySQL, MariaDB, PostgreSQL). Centralised here so {@see likePattern()} (which
     * escapes with it) and the `ESCAPE` clause ({@see applyLike()}) can never diverge.
     */
    private const ESCAPE = '!';

    /**
     * Global search across the resources declared in config('admin-core.search'). Each entry:
     *   ['model' => Product::class, 'columns' => ['name', 'slug'], 'label' => 'Products',
     *    'route' => 'admin.products.edit', 'key' => 'uuid', 'icon' => 'bi bi-box-seam',
     *    'service' => ProductService::class]
     * - columns: LIKE-matched (no external search engine / dependency; works offline).
     * - route + key: builds the result link (key column = the route param; defaults to the model key).
     * - service (optional): the resource's Service class. When set, search runs through its scoped query()
     *   (the SAME base query the rest of admin-core uses), so a tenant/authorization scope the Service applies
     *   also constrains search. Without it, search queries the raw model — a multi-tenant app that scopes ONLY
     *   via the Service (not a model global scope) MUST set this, or search would return cross-tenant rows.
     *
     * Returns a flat, grouped list: [['group' => , 'label' => , 'url' => , 'icon' => ], …], capped per group.
     *
     * @param  string|null  $guard  Auth guard whose user the `can` checks run against (multi-portal: e.g.
     *                              'merchant'). Null = the application's default guard. Pass a portal's guard
     *                              (Route::adminCoreSearch('merchant')) or the gate resolves the wrong user.
     *
     * @return array<int, array{group: string, label: string, url: string|null, icon: string}>
     */
    public static function query(string $term, int $perGroup = 5, ?string $guard = null): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $results = [];
        foreach ((array) config('admin-core.search', []) as $cfg) {
            $model = $cfg['model'] ?? null;
            $columns = array_values((array) ($cfg['columns'] ?? []));
            if (! is_string($model) || ! class_exists($model) || $columns === []) {
                continue;
            }

            // Don't leak records the user can't list: gate each entry on its permission — explicit
            // (`'permission' => 'list-foo'`) or, by default, the convention `list-{kebab(ClassBasename)}`
            // that admin-core:make grants. Set `'permission' => null` on an entry to opt out of the gate.
            // Resolve the user on THIS portal's guard (a portal user isn't on the default guard), and FAIL SAFE:
            // when a permission is required but no user resolves, skip the entry rather than returning everything.
            if (config('admin-core.permission.enabled', true)) {
                $permission = array_key_exists('permission', $cfg)
                    ? $cfg['permission']
                    : 'list-' . \Illuminate\Support\Str::kebab(class_basename($model));
                if (is_string($permission) && $permission !== '') {
                    $user = auth()->guard($guard)->user();
                    if ($user === null || ! $user->can($permission)) {
                        continue;
                    }
                }
            }

            $casts = (new $model)->getCasts();
            $locale = app()->getLocale();

            // Run through the resource's Service query() when declared, so its tenant/authorization scope also
            // constrains search — a raw $model::query() would bypass a scope applied only in the Service.
            $service = $cfg['service'] ?? null;
            $base = (is_string($service) && $service !== '' && (class_exists($service) || app()->bound($service)))
                ? app($service)->query()
                : $model::query();

            $rows = $base
                ->where(function ($q) use ($columns, $term, $casts, $locale) {
                    foreach ($columns as $col) {
                        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
                            continue; // only plain column identifiers are searchable
                        }
                        // A translatable / JSON-cast column matches ONE locale's value (never the raw JSON
                        // blob, which would match across locales + JSON syntax); every other column matches
                        // directly. Both route through the shared, driver-portable LIKE builders below, so the
                        // escaping and case handling live in exactly one place.
                        if (in_array($casts[$col] ?? null, ['array', 'json', 'object', 'collection'], true)) {
                            self::whereJsonLike($q, $col, (string) $locale, $term, 'or');
                        } else {
                            self::whereLike($q, $col, $term, 'or');
                        }
                    }
                })
                ->limit($perGroup)
                ->get();

            $key = $cfg['key'] ?? null;
            foreach ($rows as $row) {
                $results[] = [
                    'group' => (string) ($cfg['label'] ?? class_basename($model)),
                    'label' => self::label($row, $columns),
                    'url' => isset($cfg['route'])
                        ? route($cfg['route'], [$key !== null ? $row->{$key} : $row->getKey()])
                        : null,
                    'icon' => (string) ($cfg['icon'] ?? 'bi bi-dot'),
                ];
            }
        }

        return $results;
    }

    /**
     * Escape a user term's LIKE metacharacters (`%`, `_`) — and the {@see ESCAPE} sentinel itself — so they
     * match LITERALLY, returning the ready `%term%` pattern. Without this an underscore is a single-char
     * wildcard and a `%` matches anything, so a search over-matches rows the user never asked for. Pairs with
     * the `ESCAPE '<sentinel>'` clause emitted by {@see applyLike()}, which every supported driver honours.
     */
    public static function likePattern(string $term): string
    {
        // Escape the sentinel FIRST (so a literal sentinel the user typed survives), then the wildcards.
        return '%' . str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE . self::ESCAPE, self::ESCAPE . '%', self::ESCAPE . '_'],
            $term
        ) . '%';
    }

    /**
     * Apply an escaped, case-insensitive, DRIVER-PORTABLE LIKE on a plain column to a query builder — the one
     * place every admin-core search path (API list search, list-filter text, the select remote source, the
     * media library, and the generated relation filterColumn) routes through, so the escaping can never drift
     * out of one of them again. The column is validated as a bare identifier then quoted BY THE CONNECTION'S
     * QUERY GRAMMAR (never a hardcoded backtick), the match is case-insensitive via LOWER() on both sides
     * (consistent across drivers, matching the DataTable's native search), the `%_` in the term are escaped,
     * and the LIKE carries `ESCAPE '<sentinel>'`. A non-identifier column is skipped (never spliced into SQL).
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  'and'|'or'  $boolean
     */
    public static function whereLike($query, string $column, string $term, string $boolean = 'and'): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            return; // only plain column identifiers are searchable — never splice arbitrary text into SQL
        }

        self::applyLike($query, self::grammarFor($query)->wrap($column), $term, $boolean);
    }

    /**
     * Apply the same portable LIKE against ONE locale's value inside a translatable JSON column — the generated
     * getData() filterColumn for a `translatable` field routes through this. The column and the sanitised
     * locale are rendered as a JSON path (`col->locale`) BY THE CONNECTION'S GRAMMAR, which compiles to the
     * driver's OWN JSON accessor — `json_extract`/`json_unquote` on MySQL/SQLite, `->>` on PostgreSQL —
     * instead of a hardcoded json_extract(). The locale is sanitised to path-safe
     * chars, so a hostile app locale can never inject SQL.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  'and'|'or'  $boolean
     */
    public static function whereJsonLike($query, string $column, string $locale, string $term, string $boolean = 'and'): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            return;
        }
        $safeLocale = preg_replace('/[^A-Za-z0-9_-]/', '', $locale);
        if ($safeLocale === null || $safeLocale === '') {
            return; // an all-non-path-safe locale leaves no valid JSON path to search
        }

        self::applyLike($query, self::grammarFor($query)->wrap($column . '->' . $safeLocale), $term, $boolean);
    }

    /**
     * Build `lower(<expr>) like lower(?) escape '<sentinel>'` on the query — the single source of the LIKE
     * fragment every search path shares. `$expr` is an already-grammar-wrapped column/JSON expression (safe to
     * embed raw); the pattern is a bound parameter; the ESCAPE literal is the {@see ESCAPE} sentinel (a fixed
     * constant that is safe in a SQL string literal, never user input). LOWER() on both sides makes the match
     * case-insensitive on every driver.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  'and'|'or'  $boolean
     */
    private static function applyLike($query, string $expr, string $term, string $boolean): void
    {
        $query->whereRaw("lower({$expr}) like lower(?) escape '" . self::ESCAPE . "'", [self::likePattern($term)], $boolean);
    }

    /**
     * The connection's query grammar for either an Eloquent or a base query builder — used to quote the
     * identifier and render the JSON path PER DRIVER, so the emitted SQL is never dialect-locked to one
     * database (the root cause of the pre-2.86.1 non-portability).
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    private static function grammarFor($query): \Illuminate\Database\Query\Grammars\Grammar
    {
        $base = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getQuery() : $query;
        /** @var \Illuminate\Database\Query\Builder $base */

        return $base->getGrammar();
    }

    /** First non-empty searched column as the display label (handles translatable JSON columns). */
    private static function label(object $row, array $columns): string
    {
        foreach ($columns as $col) {
            $value = $row->{$col} ?? null;
            if (is_array($value)) {
                $value = $value[app()->getLocale()] ?? (reset($value) ?: null);
            }
            // A searched column may be ENUM-CAST (status:enum) — an enum object can't be (string)-cast, so
            // unwrap it (backing value, or case name for a pure enum) as we do for the transition state column.
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof \UnitEnum) {
                $value = $value->name;
            }
            if (filled($value)) {
                return (string) $value;
            }
        }

        return (string) $row->getKey();
    }
}
