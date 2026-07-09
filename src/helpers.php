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

if (! function_exists('ac_label')) {
    /**
     * The human label for a generated resource field, resolved at RUNTIME so a product can localise or
     * override it in ONE place — a normal app lang file `lang/{locale}/{resource}.php`:
     *
     *     return ['fields' => ['cert_validity_months' => 'Certificate Validity (Months)']];
     *
     * Resolution: the product override for the current locale, then the fallback locale (Laravel's own
     * `__()` fallback), then a humanised field name. A missing key NEVER renders — `__()` returns the raw
     * key on a miss, which we detect and replace with `Str::headline()`. admin-core writes no lang file and
     * owns no label text; the framework supplies only this mechanism and the headline default. The generated
     * views call this at every field-label surface (form / table header / show / export / list-filters).
     */
    function ac_label(string $resource, string $field): string
    {
        $key = "{$resource}.fields.{$field}";
        $label = __($key);

        return is_string($label) && $label !== $key
            ? $label
            : \Illuminate\Support\Str::headline($field);
    }
}

if (! function_exists('ac_menu_label')) {
    /**
     * Safely translate a DYNAMIC UI label (menu item, section header, dashboard control, …). Laravel's
     * `__()` treats a bare word that matches a PHP translation GROUP file — e.g. a menu label "Courses"
     * with `lang/en/courses.php` present — as a group and returns the WHOLE array, which Blade's `{{ }}`
     * then hands to `htmlspecialchars()`, throwing a TypeError (a 500 on every page). This became common
     * once resources ship a per-resource label file (FI-2). Guard it: use the translation only when it is a
     * string, else fall back to the raw label. Never returns a non-string; never throws.
     */
    function ac_menu_label(string $label): string
    {
        $translated = __($label);

        return is_string($translated) ? $translated : $label;
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

if (! function_exists('ac_bt_options')) {
    /**
     * The `[id => name]` selected options for a belongsToMany field's edit-form multi-select — INCLUDING
     * attached rows that have been soft-deleted. A plain `$model->relation` applies the related model's
     * SoftDeletes scope, so a soft-deleted-but-attached row is omitted from the selected set → the next save's
     * `sync()` (which replaces the pivot with only the posted ids) silently DETACHES it, losing pivot data the
     * user never saw. Resolving withTrashed() keeps it selected, so sync() preserves the attachment.
     *
     * @return array<int|string, string>
     */
    function ac_bt_options(?object $model, string $relation): array
    {
        if ($model === null || ! method_exists($model, $relation)) {
            return [];
        }

        $query = $model->{$relation}();
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($query->getRelated()), true)) {
            $query = $query->withTrashed();
        }

        return $query->get()->mapWithKeys(fn ($i) => [$i->getKey() => ac_localize($i->name)])->all();
    }
}

if (! function_exists('ac_find')) {
    /**
     * Find a related model by id for a `--derived` recompute — INCLUDING a soft-deleted row. A plain find()
     * applies the SoftDeletes scope, so once the source row (e.g. the Unit a qty_base is derived from) is
     * soft-deleted, the next save of ANY field would re-fetch null and recompute the denormalised value to 0,
     * silently corrupting a previously-correct number. Resolves withTrashed() when the model supports it.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  class-string<TModel>  $modelClass
     * @return TModel|null
     */
    function ac_find(string $modelClass, int|string|null $id)
    {
        if ($id === null) {
            return null;
        }

        $query = $modelClass::query();
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            // withoutGlobalScope(SoftDeletingScope) === withTrashed(), but it's a base-Builder method (so it
            // type-checks against a variable class-string, unlike the SoftDeletes macro withTrashed()).
            $query->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class);
        }

        return $query->find($id);
    }
}
