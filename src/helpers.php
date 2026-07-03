<?php

if (! function_exists('setting')) {
    /**
     * Read an admin-core setting (cached). Falls back to $default when the
     * Settings module isn't installed (e.g. a minimal admin-core:install).
     */
    function setting(string $key, $default = null)
    {
        return class_exists(\App\Models\Setting::class)
            ? \App\Models\Setting::get($key, $default)
            : $default;
    }
}

if (! function_exists('ac_localize')) {
    /**
     * Resolve a possibly-translatable value (a [locale => text] array, as stored by a `translatable`
     * field) to a plain string in the current locale. A plain string passes through unchanged.
     *
     * Use it anywhere a value MIGHT be translatable — e.g. a related model's `name` shown in a
     * DataTable column, a <select> option, or a show row — so echoing it can never hit
     * `htmlspecialchars(): array given`. The generator emits this around every foreign-key display.
     */
    function ac_localize($value): string
    {
        if (is_array($value)) {
            // The current locale falls through when its value is BLANK (''), not just absent — a record
            // saved with only one language filled must show that language, not an empty cell.
            $current = $value[app()->getLocale()] ?? null;

            return (string) (filled($current) ? $current : (collect($value)->first(fn ($v) => filled($v)) ?? ''));
        }

        return (string) ($value ?? '');
    }
}

if (! function_exists('ac_fk_option')) {
    /**
     * The `[id => name]` option for a belongsTo field's edit-form select — resolving the current related row
     * EVEN WHEN IT HAS BEEN SOFT-DELETED. A plain `$model->relation` applies the related model's SoftDeletes
     * scope, so a soft-deleted parent resolves to null → the select renders without its selected option → the
     * next save (of any field) posts an empty value and silently NULLS the foreign key. This looks the row up
     * withTrashed() when the related model supports it, keeping the option present. Returns [] when there's no
     * related row (a genuinely null FK).
     *
     * @return array<int|string, string>
     */
    function ac_fk_option(?object $model, string $relation, string $fkColumn): array
    {
        if ($model === null) {
            return [];
        }

        $related = $model->{$relation};
        if ($related === null && $model->{$fkColumn} !== null && method_exists($model, $relation)) {
            $query = $model->{$relation}();
            $target = $query->getRelated();
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($target), true)) {
                $related = $query->withTrashed()->first();
            }
        }

        return $related ? [$model->{$fkColumn} => ac_localize($related->name)] : [];
    }
}
