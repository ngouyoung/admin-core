<?php

namespace Ngos\AdminCore\Support;

class Search
{
    /**
     * Global search across the resources declared in config('admin-core.search'). Each entry:
     *   ['model' => Product::class, 'columns' => ['name', 'slug'], 'label' => 'Products',
     *    'route' => 'admin.products.edit', 'key' => 'uuid', 'icon' => 'bi bi-box-seam']
     * - columns: LIKE-matched (no external search engine / dependency; works offline).
     * - route + key: builds the result link (key column = the route param; defaults to the model key).
     *
     * Returns a flat, grouped list: [['group' => , 'label' => , 'url' => , 'icon' => ], …], capped per group.
     *
     * @return array<int, array{group: string, label: string, url: string|null, icon: string}>
     */
    public static function query(string $term, int $perGroup = 5): array
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
            if (config('admin-core.permission.enabled', true) && ($user = auth()->user())) {
                $permission = array_key_exists('permission', $cfg)
                    ? $cfg['permission']
                    : 'list-' . \Illuminate\Support\Str::kebab(class_basename($model));
                if (is_string($permission) && $permission !== '' && ! $user->can($permission)) {
                    continue;
                }
            }

            $casts = (new $model)->getCasts();
            $locale = app()->getLocale();
            $rows = $model::query()
                ->where(function ($q) use ($columns, $term, $casts, $locale) {
                    foreach ($columns as $col) {
                        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
                            continue; // only plain column identifiers are searchable
                        }
                        if (in_array($casts[$col] ?? null, ['array', 'json', 'object', 'collection'], true)) {
                            // Translatable / JSON column: match the active locale's value, not the raw JSON
                            // blob (which would match across locales and JSON syntax). Backtick-quoted
                            // identifier works on MySQL + SQLite; the column name is validated above.
                            $q->orWhereRaw("json_extract(`{$col}`, '$.\"{$locale}\"') LIKE ?", ['%' . $term . '%']);
                        } else {
                            $q->orWhere($col, 'like', '%' . $term . '%');
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
            if (filled($value)) {
                return (string) $value;
            }
        }

        return (string) $row->getKey();
    }
}
