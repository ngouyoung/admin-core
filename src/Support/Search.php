<?php

namespace Ngos\AdminCore\Support;

class Search
{
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

            // Escape the LIKE metacharacters (%, _, \) in the user's term so they match LITERALLY — an
            // underscore in the query used to act as a single-char wildcard (searching 'a_c' matched 'aXc'),
            // returning rows the user never searched for. The pattern carries an explicit ESCAPE '\' (portable
            // across MySQL + SQLite; MySQL's default is already '\', SQLite has none unless stated).
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';

            $rows = $base
                ->where(function ($q) use ($columns, $like, $casts, $locale) {
                    foreach ($columns as $col) {
                        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
                            continue; // only plain column identifiers are searchable
                        }
                        if (in_array($casts[$col] ?? null, ['array', 'json', 'object', 'collection'], true)) {
                            // Translatable / JSON column: match the active locale's value, not the raw JSON
                            // blob (which would match across locales and JSON syntax). Backtick-quoted
                            // identifier works on MySQL + SQLite; the column name is validated above.
                            //
                            // The JSON PATH is a BOUND parameter, never string-interpolated: app()->getLocale()
                            // is a global mutable value (any middleware/host code can set it, and Search doesn't
                            // depend on admin-core's allowlisting SetLocale), so splicing it into the SQL was a
                            // latent injection. Binding it makes a hostile locale a harmless wrong-path lookup;
                            // sanitising to locale-safe chars keeps the path well-formed too.
                            $safeLocale = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale);
                            $q->orWhereRaw("json_extract(`{$col}`, ?) LIKE ? ESCAPE '\\'", ['$."' . $safeLocale . '"', $like]);
                        } else {
                            $q->orWhereRaw("`{$col}` LIKE ? ESCAPE '\\'", [$like]);
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
