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

if (! function_exists('ac_display_column')) {
    /**
     * The column holding a related model's human-readable label — used for a belongsTo/belongsToMany
     * <select>'s current-value option AND its remote search, and for a related column in a CSV export, so all
     * of them resolve the SAME column (never one by `title` and another by `name`). Order: an explicit model
     * override (a `displayColumn()` method), then the first conventional column present on the table — `name`,
     * then `title`, then `label` (name first, so existing `name`-based resources are unchanged) — and finally
     * the model's route key (always a real column, so a remote search never targets a column that does not
     * exist). Framework mechanism: it inspects column NAMES only, never what the model represents. Memoised per
     * model class (a hot path in exports).
     */
    function ac_display_column(\Illuminate\Database\Eloquent\Model $model): string
    {
        // The descriptor is the single internal read source (RFC-0009 Rev 2, Backlog #5). This backward-compatible
        // string facade returns the descriptor's backing column — or, the unchanged legacy behavior, the model's
        // route key for a label-less (`none`) model. Type-based resolution (composition fidelity: from the
        // immutable class, never a mutable instance getTable()), the name→title→label order, the displayColumn()
        // override, and per-class memoisation all live in ac_display_descriptor(), so this facade is byte-for-byte
        // compatible for every existing caller. Read-side consumers (ac_related_label, WebController::select /
        // ::export) resolve labels through this facade and are thereby wired to the descriptor with no duplication.
        return ac_display_descriptor($model)['column'] ?? $model->getRouteKeyName();
    }
}

if (! function_exists('ac_display_descriptor')) {
    /**
     * The full display-column DESCRIPTOR for a model type (RFC-0009 Rev 2): its KIND — a backing `column`, a
     * model-`computed` label, or explicit `none` (no label column) — plus the columns that must be hydrated to
     * render it. This is the additive, READ-SIDE companion to {@see ac_display_column()}: it exposes the same
     * type-level resolution as structured metadata. It resolves from the IMMUTABLE class (composition fidelity —
     * never the passed instance's getTable(), which a self-referential relation query may have aliased) and is
     * memoised per class, so the result is deterministic and Octane-safe. Nothing consumes this yet, and
     * {@see ac_display_column()} is unchanged — a label-less model still yields its route-key string there while
     * this descriptor reports the explicit `none`.
     *
     * The concrete array REPRESENTATION is an INTERNAL implementation detail. RFC-0009 Rev 2 guarantees only the
     * descriptor SEMANTICS (its kind, the backing column when there is one, and the columns to hydrate) — never
     * this array layout, its key names, or any serialization. The representation may evolve in a future release
     * without changing the architectural contract; read it through this helper, do not depend on the literal shape.
     *
     * @return array{kind: 'column'|'computed'|'none', column: string|null, columns: list<string>}
     */
    function ac_display_descriptor(\Illuminate\Database\Eloquent\Model $model): array
    {
        static $memo = [];
        $class = $model::class;
        if (isset($memo[$class])) {
            return $memo[$class];
        }
        // Resolve the schema from the TYPE's canonical table (a fresh instance of the class), matching
        // ac_display_column()'s composition-fidelity fix — never the passed instance's possibly-aliased getTable().
        $table = (new $class)->getTable();

        if (method_exists($model, 'displayColumn')) {
            $declared = (string) $model->{'displayColumn'}();

            // A declared display column that exists on the table is a plain `column`; one that does not is a
            // model-`computed` label (an accessor the model resolves itself) — a bare name declares no columns.
            return $memo[$class] = \Illuminate\Support\Facades\Schema::hasColumn($table, $declared)
                ? ['kind' => 'column', 'column' => $declared, 'columns' => [$declared]]
                : ['kind' => 'computed', 'column' => $declared, 'columns' => []];
        }

        foreach (['name', 'title', 'label'] as $column) {
            if (\Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                return $memo[$class] = ['kind' => 'column', 'column' => $column, 'columns' => [$column]];
            }
        }

        // No conventional label column — explicit `none`.
        return $memo[$class] = ['kind' => 'none', 'column' => null, 'columns' => []];
    }
}

if (! function_exists('ac_related_label')) {
    /**
     * A related model's human label for display — its {@see ac_display_column()} value, localized. Null-safe:
     * returns null for a null relation (a genuinely absent belongsTo). Used by generated list/show/API/trash
     * surfaces so they render a title-based model's `title` (or its resolved display column), never a hardcoded
     * `name`. Pairs with ac_display_column() for the SQL side (filter/sort), so display and query agree.
     */
    function ac_related_label(?\Illuminate\Database\Eloquent\Model $related): ?string
    {
        if ($related === null) {
            return null;
        }

        // Resolve the relation's display column through the formalized RESOURCE view-config (RFC-0009 Rev 2).
        $display = \Ngos\AdminCore\Support\View\RelationDisplayColumn::for($related);

        // Explicit NONE (RFC-0009 Rev 2) — DEFAULT since v3.0.0. A genuinely label-less related model (descriptor
        // kind 'none': no name/title/label and no displayColumn() override) resolves to NONE (null / an empty cell)
        // instead of leaking a route key. The `explicit_none` flag remains only as a DEPRECATED opt-out — set it to
        // false to restore the pre-v3.0.0 route-key fallback during migration (to be removed in a future major). A
        // 'computed' label is deliberately NOT gated (its accessor IS a real value), and this gate touches neither
        // the descriptor, RelationDisplayColumn, nor search/sort (labelColumn() below is byte-for-byte ac_display_column()).
        if ($display->kind === 'none' && config('admin-core.explicit_none', false)) {
            return null;
        }

        return ac_localize($related->{$display->labelColumn()});
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

        return $related ? [$model->{$fkColumn} => ac_localize($related->{ac_display_column($related)})] : [];
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

        return $query->get()->mapWithKeys(fn ($i) => [$i->getKey() => ac_localize($i->{ac_display_column($i)})])->all();
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
