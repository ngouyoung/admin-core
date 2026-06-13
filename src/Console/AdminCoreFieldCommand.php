<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Add one or more fields to an EXISTING admin-core resource — the part the
 * scaffolder can't do on a re-run. Generates an `add_…_to_…_table` migration and
 * surgically patches the model ($fillable + casts), the store/update requests,
 * the form/thead/scripts views and the factory. Fields that already exist on the
 * resource are detected and skipped, so it's safe to re-run.
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
        $existing = $this->modelFillable($model);
        $newTokens = [];
        $skipped = [];
        foreach (array_filter(array_map('trim', explode(',', $this->argument('fields')))) as $token) {
            $fieldName = trim(explode(':', $token)[0]);
            in_array($fieldName, $existing, true) ? $skipped[] = $fieldName : $newTokens[] = $token;
        }

        foreach ($skipped as $name) {
            $this->warn("  already exists — skipped: {$name}");
        }

        // Relation / upload fields need wiring this command can't surgically patch
        // (model relations, the controller's getData eager-load/addColumn, the
        // service's pivot-sync or file-storage). Skip them rather than leave a
        // half-wired resource — point the user at the full generator.
        $needsWiring = array_map(fn ($f) => $f['name'], array_filter(
            (new FieldSet(implode(', ', $newTokens)))->fields(),
            fn ($f) => in_array($f['type'], ['foreign', 'belongsToMany', 'image', 'file'], true),
        ));
        foreach ($needsWiring as $name) {
            $this->warn("  needs relation/upload wiring — skipped: {$name} (regenerate with `admin-core:make {$class} --fields=\"…\" --force`, or wire it by hand)");
        }
        $newTokens = array_filter($newTokens, fn ($t) => ! in_array(trim(explode(':', $t)[0]), $needsWiring, true));

        if (! $newTokens) {
            $this->info($needsWiring
                ? "Nothing added — the field(s) above need the full generator (relations/uploads)."
                : "Nothing to add — every field already exists on {$class}.");

            return self::SUCCESS;
        }

        $fs = (new FieldSet(implode(', ', $newTokens)))->setTable($snakePlural)->setClass($class);
        $fields = $fs->fields();
        $names = array_map(fn ($f) => $f['name'], $fields);

        $this->writeMigration($snakePlural, $fs, $names);
        $this->patchModel($model, $fs, $fields);
        $this->patchRequest(app_path("Http/Requests/{$class}/Store{$class}Request.php"), $fs->storeRules());
        $this->patchRequest(app_path("Http/Requests/{$class}/Update{$class}Request.php"), $fs->updateRules());
        $this->patchForm(resource_path("views/backend/pages/{$snakePlural}/partials/form.blade.php"), $fs->formFields());
        $this->patchThead(resource_path("views/backend/pages/{$snakePlural}/partials/thead.blade.php"), $fields, $fs);
        $this->patchScripts(resource_path("views/backend/pages/{$snakePlural}/partials/scripts.blade.php"), $fields, $fs);
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

        File::put($path, $contents);
        $this->line('  <info>patched</info> ' . $this->relative($path) . ' (fillable' . ($castLines ? ' + casts' : '') . ')');
    }

    /** Insert rule lines before the closing `];` of a FormRequest's rules() array. */
    private function patchRequest(string $path, string $rules): void
    {
        if (! File::exists($path) || trim($rules) === '') {
            return;
        }

        $patched = preg_replace(
            '/(public function rules\(\): array\s*\{\s*return \[)(.*?)(\n\s*\];)/s',
            "$1$2\n{$rules}$3",
            File::get($path),
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

    /** Insert new <th> cells before the Actions column. */
    private function patchThead(string $path, array $fields, FieldSet $fs): void
    {
        if (! File::exists($path)) {
            return;
        }

        $cells = implode("\n", array_map(fn ($f) => $fs->fieldTh($f), $fields));
        $contents = File::get($path);
        $patched = preg_replace('/(\n\s*<th>Actions<\/th>)/', "\n{$cells}$1", $contents, 1);

        if ($patched !== null && $patched !== $contents) {
            File::put($path, $patched);
            $this->line('  <info>patched</info> ' . $this->relative($path));
        }
    }

    /** Insert new DataTable columns before the actions column. */
    private function patchScripts(string $path, array $fields, FieldSet $fs): void
    {
        if (! File::exists($path)) {
            return;
        }

        $cols = implode("\n", array_map(fn ($f) => $fs->fieldColumn($f), $fields));
        $contents = File::get($path);
        $patched = preg_replace("/(\n\s*\{data: 'actions')/", "\n{$cols}$1", $contents, 1);

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
