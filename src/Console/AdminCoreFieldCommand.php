<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Add one or more fields to an EXISTING admin-core resource — the part the
 * scaffolder can't do on a re-run. Generates an `add_…_to_…_table` migration and
 * surgically patches the model ($fillable + casts), the store/update requests,
 * the form/thead/scripts/show views and the factory (plus the API resource +
 * whitelists when the resource has an --api channel). Fields that already exist on
 * the resource are detected and skipped, so it's safe to re-run.
 *
 *   php artisan admin-core:field Product "sku:string^, discount:decimal?"
 */
class AdminCoreFieldCommand extends Command
{
    protected $signature = 'admin-core:field
                            {name : The existing resource, e.g. Product}
                            {fields : Field DSL to add, e.g. "sku:string, discount:decimal?"}
                            {--force : Patch even files that look hand-edited}';

    protected $description = 'Add fields to an existing admin-core resource (migration + model + requests + views + factory).';

    public function handle(): int
    {
        $class = Str::studly(Str::singular($this->argument('name')));
        $snakePlural = Str::snake(Str::pluralStudly($class));

        $model = app_path("Models/{$class}.php");
        if (! File::exists($model)) {
            $this->error("Resource '{$class}' not found (no app/Models/{$class}.php). Run `admin-core:make {$class}` first.");

            return self::FAILURE;
        }

        // The add migration is `Schema::table(...)` — it needs the table to exist
        // (or a pending create migration that will make it). Bail before patching
        // anything if there's neither, so we never leave a migration that can't run.
        if (! $this->tableExists($snakePlural) && ! glob(base_path("database/migrations/*_create_{$snakePlural}_table.php"))) {
            $this->error("Table '{$snakePlural}' doesn't exist and there's no create migration for it.");
            $this->line("  Create it first: <info>php artisan admin-core:make {$class} --migration && php artisan migrate</info>");

            return self::FAILURE;
        }

        // Drop fields that already exist (idempotent — never duplicate a column/rule/input).
        // We check BOTH the model's $fillable AND the real DB column: a column can exist
        // without being fillable (a system field, a hand-added column, or fillable drift),
        // and adding it again would write an add-migration that fails with SQLSTATE[42S21]
        // "Duplicate column" on migrate.
        $existing = $this->modelFillable($model);
        $newTokens = [];
        $skipped = [];
        foreach (array_filter(array_map('trim', explode(',', $this->argument('fields')))) as $token) {
            $fieldName = trim(explode(':', $token)[0]);
            if (in_array($fieldName, $existing, true) || $this->columnExists($snakePlural, $fieldName)) {
                $skipped[] = $fieldName;
            } else {
                $newTokens[] = $token;
            }
        }

        foreach ($skipped as $name) {
            $this->warn("  already exists — skipped: {$name}");
        }

        // Some field types need wiring this command can't surgically patch:
        //  - relations / uploads (model relations, getData eager-load, pivot-sync, file-storage);
        //  - system fields (@/sku/auth) — not mass-assignable, so the $fillable idempotency check
        //    can't track them (re-runs would duplicate) and they need a booted() value-setter.
        // Skip them rather than leave a half-wired resource — point the user at the full generator.
        try {
            $parsed = (new FieldSet(implode(', ', $newTokens)))->fields();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $needsWiring = array_map(fn ($f) => $f['name'], array_filter(
            $parsed,
            fn ($f) => in_array($f['type'], ['foreign', 'belongsToMany', 'image', 'file'], true) || ! empty($f['system']),
        ));
        foreach ($needsWiring as $name) {
            $this->warn("  needs the full generator — skipped: {$name} (relation/upload/system field; regenerate with `admin-core:make {$class} --fields=\"…\" --force`, or wire it by hand)");
        }
        $newTokens = array_filter($newTokens, fn ($t) => ! in_array(trim(explode(':', $t)[0]), $needsWiring, true));

        if (! $newTokens) {
            $this->info($needsWiring
                ? "Nothing added — the field(s) above need the full generator (relations/uploads)."
                : "Nothing to add — every field already exists on {$class}.");

            return self::SUCCESS;
        }

        $fs = (new FieldSet(implode(', ', $newTokens)))->setTable($snakePlural)->setClass($class)
            ->setHasName(in_array('name', $existing, true)); // so a slug derives from the model's existing name
        $fields = $fs->fields();
        $names = array_map(fn ($f) => $f['name'], $fields);

        $this->writeMigration($snakePlural, $fs, $names);
        $this->patchModel($model, $fs, $fields);
        $this->patchBoot($model, $fs);
        $this->patchRequest(app_path("Http/Requests/{$class}/Store{$class}Request.php"), $fs->storeRules());
        $this->patchRequest(app_path("Http/Requests/{$class}/Update{$class}Request.php"), $fs->updateRules());
        // json fields decode their textarea string to an array; a blank password on update is dropped.
        $this->patchPrepare(app_path("Http/Requests/{$class}/Store{$class}Request.php"), $fs->prepareBody(false));
        $this->patchPrepare(app_path("Http/Requests/{$class}/Update{$class}Request.php"), $fs->prepareBody(true));
        $this->patchForm(resource_path("views/backend/pages/{$snakePlural}/partials/form.blade.php"), $fs->formFields());
        $this->patchThead(resource_path("views/backend/pages/{$snakePlural}/partials/thead.blade.php"), $fields, $fs);
        $this->patchScripts(resource_path("views/backend/pages/{$snakePlural}/partials/scripts.blade.php"), $fields, $fs);
        $this->patchIndexColumns(resource_path("views/backend/pages/{$snakePlural}/index.blade.php"), $fields, $fs);
        $this->patchController(app_path("Http/Controllers/Backend/{$class}Controller.php"), $fs, $fields);
        $this->patchShow(resource_path("views/backend/pages/{$snakePlural}/show.blade.php"), $fs->showRows());
        $this->patchFactory(database_path("factories/{$class}Factory.php"), $fs->factoryDefinition());
        $this->patchApiResource($class, $fs);
        $this->patchApiWhitelists($class, $fields);
        $this->writeEnums($fs);

        $this->newLine();
        $this->info('Added ' . implode(', ', $names) . " to {$class}.");
        $this->line('  Run <info>php artisan migrate</info> to apply the new column(s).');

        return self::SUCCESS;
    }

    /** Whether the DB table exists (false if there's no usable connection). */
    private function tableExists(string $table): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether the column already exists on the table — so we never write a duplicate-column migration. */
    private function columnExists(string $table, string $column): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Field names in the model's $fillable array. */
    private function modelFillable(string $path): array
    {
        if (preg_match('/protected \$fillable = \[(.*?)\];/s', File::get($path), $m)
            && preg_match_all("/'([^']+)'/", $m[1], $names)) {
            return $names[1];
        }

        return [];
    }

    private function writeMigration(string $table, FieldSet $fs, array $names): void
    {
        $existing = glob(base_path("database/migrations/*_add_*_to_{$table}_table.php")) ?: [];
        foreach ($existing as $file) {
            if (str_contains(File::get($file), "'" . $names[0] . "'")) {
                $this->warn('  migration for these fields looks present — skipped');

                return;
            }
        }

        $target = base_path('database/migrations/' . date('Y_m_d_His') . '_add_' . implode('_', $names) . "_to_{$table}_table.php");
        $drop = implode(', ', array_map(fn ($n) => "'{$n}'", $names));

        File::put($target, strtr(File::get($this->stub('add-migration.stub')), [
            '__AC_TABLE__' => $table,
            '__AC_COLUMNS__' => $fs->migrationColumns(),
            '__AC_DROP__' => $drop,
        ]));
        $this->line('  <info>created</info> ' . $this->relative($target));
    }

    private function patchModel(string $path, FieldSet $fs, array $fields): void
    {
        $contents = File::get($path);

        // 1. $fillable — append the new names.
        $contents = preg_replace_callback(
            '/(protected \$fillable = \[)(.*?)(\];)/s',
            fn ($m) => $m[1] . rtrim($m[2]) . ', ' . $fs->fillable() . $m[3],
            $contents,
            1,
        );

        // 2. casts — extend the existing casts() method, or add one.
        $castLines = [];
        foreach ($fields as $f) {
            if ($cast = $fs->fieldCast($f)) {
                $castLines[] = "            '{$f['name']}' => {$cast},";
            }
        }
        if ($castLines) {
            $block = implode("\n", $castLines);
            if (str_contains($contents, 'protected function casts(): array')) {
                $contents = preg_replace(
                    '/(protected function casts\(\): array\s*\{\s*return \[)(.*?)(\n\s*\];)/s',
                    "$1$2\n{$block}$3",
                    $contents,
                    1,
                );
            } else {
                // No casts() yet — drop a fresh one in right after $fillable.
                $contents = preg_replace(
                    '/(protected \$fillable = \[.*?\];\n)/s',
                    '$1' . $fs->casts() . "\n",
                    $contents,
                    1,
                );
            }
        }

        // 3. $hidden — keep new password columns out of array/JSON output (extend or add).
        $hiddenCols = $fs->hiddenColumns();
        if ($hiddenCols !== '') {
            if (preg_match('/protected \$hidden = \[/', $contents)) {
                $contents = preg_replace_callback(
                    '/(protected \$hidden = \[)(.*?)(\];)/s',
                    fn ($m) => $m[1] . ($m[2] === '' ? '' : rtrim($m[2]) . ', ') . $hiddenCols . $m[3],
                    $contents,
                    1,
                );
            } else {
                $contents = preg_replace(
                    '/(protected \$fillable = \[.*?\];\n)/s',
                    "$1\n    protected \$hidden = [{$hiddenCols}];\n",
                    $contents,
                    1,
                );
            }
        }

        File::put($path, $contents);
        $touched = array_filter(['fillable', $castLines ? 'casts' : '', $hiddenCols !== '' ? 'hidden' : '']);
        $this->line('  <info>patched</info> ' . $this->relative($path) . ' (' . implode(' + ', $touched) . ')');
    }

    /**
     * Add (or extend) the model's booted() creating-hook so a newly-added slug auto-derives
     * from `name`. Extends an existing static::creating closure, or appends a fresh booted()
     * method before the class brace. (System fields are skipped upstream, so this is slug-only.)
     */
    private function patchBoot(string $path, FieldSet $fs): void
    {
        $body = $fs->bootBody();
        if (trim($body) === '') {
            return;
        }

        $contents = File::get($path);

        if (str_contains($contents, 'function booted(')) {
            // Drop the assignments at the top of the existing creating() closure.
            $patched = preg_replace(
                '/(static::creating\(function \(self \$model\) \{)/',
                "$1\n{$body}",
                $contents,
                1,
            );
            if ($patched === null || $patched === $contents) {
                $this->warn('  ' . $this->relative($path) . ' has a custom booted() without a creating() hook — add the slug derive by hand');

                return;
            }
        } else {
            // Append a fresh booted() method just before the class's closing brace.
            $patched = rtrim(rtrim($contents), '}') . $fs->modelBoot() . "\n}\n";
        }

        File::put($path, $patched);
        $this->line('  <info>patched</info> ' . $this->relative($path) . ' (booted hook)');
    }

    /** Insert rule lines before the closing `];` of a FormRequest's rules() array. */
    private function patchRequest(string $path, string $rules): void
    {
        if (! File::exists($path) || trim($rules) === '') {
            return;
        }

        $contents = File::get($path);

        // The update rule for a unique field uses an unqualified `Rule::unique(...)`. A
        // resource generated with no unique field has no `use Illuminate\Validation\Rule;`,
        // so without this the patched request fatals with "Class Rule not found".
        if (str_contains($rules, 'Rule::unique(') && ! str_contains($contents, 'use Illuminate\Validation\Rule;')) {
            $contents = preg_replace(
                '/(use Illuminate\\\\Foundation\\\\Http\\\\FormRequest;)/',
                "$1\nuse Illuminate\\Validation\\Rule;",
                $contents,
                1,
            );
        }

        $patched = preg_replace(
            '/(public function rules\(\): array\s*\{\s*return \[)(.*?)(\n\s*\];)/s',
            "$1$2\n{$rules}$3",
            $contents,
            1,
        );

        if ($patched !== null) {
            File::put($path, $patched);
            $this->line('  <info>patched</info> ' . $this->relative($path));
        }
    }

    /** Append the new field blocks to the form partial. */
    private function patchForm(string $path, string $fields): void
    {
        if (! File::exists($path) || trim($fields) === '') {
            return;
        }

        File::put($path, rtrim(File::get($path)) . "\n\n" . $fields . "\n");
        $this->line('  <info>patched</info> ' . $this->relative($path));
    }

    /** Insert new <th> cells before the Actions column (write-only fields like password are skipped). */
    private function patchThead(string $path, array $fields, FieldSet $fs): void
    {
        if (! File::exists($path)) {
            return;
        }

        $shown = array_filter($fields, fn ($f) => $fs->isDisplayed($f));
        if (! $shown) {
            return;
        }
        $cells = implode("\n", array_map(fn ($f) => $fs->fieldTh($f), $shown));
        $contents = File::get($path);
        $patched = preg_replace('/(\n\s*<th>Actions<\/th>)/', "\n{$cells}$1", $contents, 1);

        if ($patched !== null && $patched !== $contents) {
            File::put($path, $patched);
            $this->line('  <info>patched</info> ' . $this->relative($path));
        }
    }

    /** Insert new DataTable columns before the actions column (write-only fields like password are skipped). */
    private function patchScripts(string $path, array $fields, FieldSet $fs): void
    {
        if (! File::exists($path)) {
            return;
        }

        $shown = array_filter($fields, fn ($f) => $fs->isDisplayed($f));
        if (! $shown) {
            return;
        }
        $cols = implode("\n", array_map(fn ($f) => $fs->fieldColumn($f), $shown));
        $contents = File::get($path);
        $patched = preg_replace("/(\n\s*\{data: 'actions')/", "\n{$cols}$1", $contents, 1);

        if ($patched !== null && $patched !== $contents) {
            File::put($path, $patched);
            $this->line('  <info>patched</info> ' . $this->relative($path));
        }
    }

    /**
     * Insert new entries into the data-driven `:columns` array of index.blade.php (resources generated
     * with v2.53+ have no scripts.blade.php — the shared datatable.js builds the table from this config).
     * No-ops on the older layout (where patchScripts handles the columns instead).
     */
    private function patchIndexColumns(string $path, array $fields, FieldSet $fs): void
    {
        if (! File::exists($path)) {
            return;
        }

        $shown = array_filter($fields, fn ($f) => $fs->isDisplayed($f));
        if (! $shown) {
            return;
        }
        $cols = implode("\n", array_map(fn ($f) => $fs->fieldColumnConfig($f), $shown));
        $contents = File::get($path);
        $patched = preg_replace("/(\n\s*\['data' => 'actions')/", "\n{$cols}$1", $contents, 1);

        if ($patched !== null && $patched !== $contents) {
            File::put($path, $patched);
            $this->line('  <info>patched</info> ' . $this->relative($path));
        }
    }

    /**
     * Patch the controller's getData(): add each new field's server-side renderer (Yes/No, formatted
     * date, status badge, …) before the actions column, and whitelist HTML-emitting cells in rawColumns —
     * so a field added later renders in the list exactly like a generated one (not a raw true/false/ISO).
     */
    private function patchController(string $path, FieldSet $fs, array $fields): void
    {
        if (! File::exists($path)) {
            return;
        }

        $contents = File::get($path);
        $before = $contents;

        // 1. Insert the new renderers just before the actions column. preg_replace_callback (not
        //    preg_replace) so the inserted "$row" closures aren't read as backreferences.
        $cols = implode("\n", array_filter(array_map(fn ($f) => $fs->fieldDataColumn($f), $fields)));
        if (trim($cols) !== '') {
            $patched = preg_replace_callback(
                "/(\n[ \t]*->addColumn\('actions')/",
                fn ($m) => "\n" . $cols . $m[1],
                $contents,
                1,
            );
            if ($patched !== null) {
                $contents = $patched;
            }
        }

        // 2. Add HTML-emitting cells to rawColumns (boolean/enum — relations/uploads are skipped upstream).
        $raw = [];
        foreach ($fields as $f) {
            if (in_array($f['type'], ['enum', 'boolean'], true)) {
                $raw[] = "'{$f['name']}'";
            }
        }
        if ($raw) {
            $patched = preg_replace_callback(
                '/(->rawColumns\(\[)([^\]]*)(\]\))/',
                fn ($m) => $m[1] . ($m[2] === '' ? '' : rtrim($m[2]) . ', ') . implode(', ', $raw) . $m[3],
                $contents,
                1,
            );
            if ($patched !== null) {
                $contents = $patched;
            }
        }

        if ($contents !== $before) {
            File::put($path, $contents);
            $this->line('  <info>patched</info> ' . $this->relative($path) . ' (getData columns)');
        }
    }

    /**
     * Add (or extend) the request's prepareForValidation() with the given body lines —
     * json decode / blank-password drop. Without this, a json field's `array` rule rejects
     * the textarea string, and a blank password on update overwrites the stored hash.
     */
    private function patchPrepare(string $path, string $body): void
    {
        if (! File::exists($path) || trim($body) === '') {
            return;
        }

        $contents = File::get($path);

        if (str_contains($contents, 'function prepareForValidation(')) {
            // Extend the existing method — insert before its closing brace.
            $patched = preg_replace(
                '/(protected function prepareForValidation\(\): void\s*\{)(.*?)(\n    \})/s',
                "$1$2\n{$body}$3",
                $contents,
                1,
            );
        } else {
            // Add the method right after the rules() method's closing brace.
            $method = "\n\n    protected function prepareForValidation(): void\n    {\n{$body}\n    }";
            $patched = preg_replace(
                '/(public function rules\(\): array\s*\{.*?\n    \})/s',
                '$1' . $method,
                $contents,
                1,
            );
        }

        if ($patched !== null && $patched !== $contents) {
            File::put($path, $patched);
            $this->line('  <info>patched</info> ' . $this->relative($path) . ' (prepareForValidation)');
        }
    }

    /** Insert detail rows before the timestamps row of the show (detail) view. */
    private function patchShow(string $path, string $rows): void
    {
        if (! File::exists($path) || trim($rows) === '') {
            return;
        }

        $contents = File::get($path);
        // Anchor on the generated timestamps row — the <x-admin-core::detail-row> that renders
        // $object->created_at — so the new fields land with the others, just above it.
        $patched = preg_replace(
            '/(\n[ \t]*<x-admin-core::detail-row\b[^>]*>\{\{ \$object->created_at)/',
            "\n{$rows}$1",
            $contents,
            1,
        );

        if ($patched !== null && $patched !== $contents) {
            File::put($path, $patched);
            $this->line('  <info>patched</info> ' . $this->relative($path));
        }
    }

    /** Insert factory definition lines before the closing `];`. */
    private function patchFactory(string $path, string $definition): void
    {
        if (! File::exists($path) || trim($definition) === '') {
            return;
        }

        $patched = preg_replace(
            '/(public function definition\(\): array\s*\{\s*return \[)(.*?)(\n\s*\];)/s',
            "$1$2\n{$definition}$3",
            File::get($path),
            1,
        );

        if ($patched !== null) {
            File::put($path, $patched);
            $this->line('  <info>patched</info> ' . $this->relative($path));
        }
    }

    /** When the resource has an --api channel, add the new fields to its JsonResource. */
    private function patchApiResource(string $class, FieldSet $fs): void
    {
        $path = app_path("Http/Resources/{$class}Resource.php");
        if (! File::exists($path) || trim($fs->resourceFields()) === '') {
            return;
        }

        $patched = preg_replace(
            "/(\n\s*'created_at' => )/",
            "\n" . $fs->resourceFields() . '$1',
            File::get($path),
            1,
        );

        if ($patched !== null && $patched !== File::get($path)) {
            File::put($path, $patched);
            $this->line('  <info>patched</info> ' . $this->relative($path) . ' (API resource)');
        }
    }

    /** When the resource has an --api controller, extend its search/sort/filter whitelists. */
    private function patchApiWhitelists(string $class, array $fields): void
    {
        $path = app_path("Http/Controllers/Api/{$class}ApiController.php");
        if (! File::exists($path)) {
            return;
        }

        $search = $sort = $filter = [];
        foreach ($fields as $f) {
            if (in_array($f['type'], ['string', 'text', 'email', 'slug', 'url'], true)) {
                $search[] = "'{$f['name']}'";
            }
            if (in_array($f['type'], ['string', 'integer', 'decimal', 'date', 'datetime', 'time', 'boolean', 'enum', 'email', 'slug', 'url'], true)) {
                $sort[] = "'{$f['name']}'";
            }
            if (in_array($f['type'], ['enum', 'boolean'], true)) {
                $filter[] = "'{$f['name']}'";
            }
        }

        $contents = File::get($path);
        $contents = $this->addToArrayProp($contents, 'searchable', $search);
        $contents = $this->addToArrayProp($contents, 'sortable', $sort);
        $contents = $this->addToArrayProp($contents, 'filterable', $filter);

        File::put($path, $contents);
        $this->line('  <info>patched</info> ' . $this->relative($path) . ' (API whitelists)');
    }

    /** Append entries to a `protected array $prop = [...]` property. */
    private function addToArrayProp(string $contents, string $prop, array $entries): string
    {
        if (! $entries) {
            return $contents;
        }

        return preg_replace_callback(
            '/(protected array \$' . $prop . ' = \[)([^\]]*)(\];)/',
            function ($m) use ($entries) {
                $existing = trim($m[2]);
                $combined = $existing === '' ? implode(', ', $entries) : $existing . ', ' . implode(', ', $entries);

                return $m[1] . $combined . $m[3];
            },
            $contents,
            1,
        );
    }

    /** Generate a backed enum class per new enum field (skips ones that exist). */
    private function writeEnums(FieldSet $fs): void
    {
        foreach ($fs->enumDefinitions() as $def) {
            $target = app_path("Enums/{$def['class']}.php");
            if (File::exists($target) && ! $this->option('force')) {
                $this->warn('  <comment>exists</comment>  ' . $this->relative($target));
                continue;
            }
            $cases = implode("\n", array_map(
                fn ($name, $value) => "    case {$name} = '{$value}';",
                array_keys($def['cases']),
                $def['cases'],
            ));
            File::ensureDirectoryExists(dirname($target));
            File::put($target, strtr(File::get($this->stub('enum.stub')), [
                '__AC_ENUM_CLASS__' => $def['class'],
                '__AC_ENUM_CASES__' => $cases,
            ]));
            $this->line('  <info>created</info> ' . $this->relative($target));
        }
    }

    private function stub(string $name): string
    {
        $published = base_path("stubs/admin-core/{$name}");

        return File::exists($published) ? $published : __DIR__ . "/../../stubs/{$name}";
    }

    private function relative(string $path): string
    {
        return Str::after($path, base_path() . DIRECTORY_SEPARATOR);
    }
}
