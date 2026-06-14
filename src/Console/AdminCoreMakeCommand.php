<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class AdminCoreMakeCommand extends Command
{
    protected $signature = 'admin-core:make {name : The resource name, e.g. Product}
                            {--fields= : Field DSL, e.g. "name:string, price:decimal?, category_id:foreign"}
                            {--uuid : Use a UUID primary key (and UUID foreign keys)}
                            {--no-uuid : Force an auto-increment key even if config enables uuid}
                            {--soft-deletes : Add soft deletes + a trash/restore screen}
                            {--audit : Log created/updated/deleted activity for this resource}
                            {--sortable : Add a drag-and-drop ordering column (sort) + reorder list}
                            {--migration : Also generate a create migration}
                            {--tests : Also generate a CRUD feature test (best paired with --migration)}
                            {--api : Also generate a JSON API (resource + controller + apiResource routes)}
                            {--api-only : Generate ONLY the JSON API (no web controller/views/routes) — add the web channel later by re-running without it}
                            {--force : Overwrite existing files}';

    protected $description = 'Scaffold a full admin-core CRUD resource (model, service, controller, requests, routes, views, permissions).';

    public function handle(): int
    {
        $class = Str::studly(Str::singular($this->argument('name')));
        $plural = Str::plural($class);
        $camel = Str::camel($class);
        $snakePlural = Str::snake(Str::pluralStudly($class));
        $kebab = Str::kebab($class);

        $uuid = $this->option('no-uuid')
            ? false
            : ($this->option('uuid') || (bool) config('admin-core.generator.uuid', false));

        $soft = (bool) $this->option('soft-deletes');

        $audit = $this->option('audit') || (bool) config('admin-core.generator.audit', false);
        $sortable = (bool) $this->option('sortable');

        // Adding a channel to an EXISTING resource? Infer the fields from its model so
        // you don't have to re-type --fields just to scaffold the API (or web) side —
        // e.g. `admin-core:make Post --api` on a web-only Post.
        $fieldsDsl = $this->option('fields');
        if (trim((string) $fieldsDsl) === '' && File::exists(app_path("Models/{$class}.php"))) {
            $inferred = $this->inferFieldsFromModel($class, $snakePlural);
            if ($inferred !== null) {
                $fieldsDsl = $inferred;
                $this->line("  <info>inferred</info> fields from {$class} model: <comment>{$inferred}</comment>");
            }
        }

        $fields = (new FieldSet($fieldsDsl))
            ->setTable($snakePlural)
            ->setUuid($uuid)
            ->setSoftDeletes($soft)
            ->setAudit($audit)
            ->setSortable($sortable)
            ->setClass($class);

        $sortRoutes = $sortable ? sprintf(
            "\n    Route::post('reorder', [%sController::class, 'reorder'])->name('reorder')\n"
            . "        ->middleware(config('admin-core.permission.enabled') ? 'permission:edit-%s' : []);",
            $class,
            $kebab,
        ) : '';

        // --sortable adds a "Sort" toggle button + a drag-and-drop panel to the
        // normal DataTable index (the table stays; sorting is an opt-in mode).
        $sortButton = $sortable
            ? "\n            <button type=\"button\" id=\"toggle-sort\" class=\"btn btn-sm btn-outline-primary\">\n"
                . "                <i class=\"fas fa-arrows-alt\"></i> Sort\n            </button>"
            : '';

        $sortPanel = $sortable ? str_replace(
            ['__CLASS__', '__SNAKE__'],
            [$class, $snakePlural],
            <<<'BLADE'

            <div id="sort-panel" class="d-none mt-2">
                @php($sortItems = \App\Models\__CLASS__::orderBy('sort')->get())
                <div class="alert alert-info py-2">Drag rows to reorder — changes save automatically.</div>
                <div class="dd nestable-lists" id="__SNAKE___sortable">
                    <ol class="dd-list">
                        @forelse ($sortItems as $sortItem)
                            <li class="dd-item" data-id="{{ $sortItem->getRouteKey() }}">
                                <div class="dd-handle">{{ $sortItem->name ?? $sortItem->id }}</div>
                            </li>
                        @empty
                            <li class="dd-item"><div class="dd-handle text-muted">No records yet.</div></li>
                        @endforelse
                    </ol>
                </div>
            </div>
            @push('scripts')
                <script>
                    document.addEventListener("DOMContentLoaded", function () {
                        const btn = document.getElementById('toggle-sort');
                        const panel = document.getElementById('sort-panel');
                        const list = $('#__SNAKE___sortable');
                        if (btn) {
                            btn.addEventListener('click', function () {
                                const sorting = panel.classList.toggle('d-none') === false;
                                $('#__SNAKE___table_wrapper').toggle(!sorting);
                                btn.innerHTML = sorting
                                    ? '<i class="fas fa-check"></i> Done'
                                    : '<i class="fas fa-arrows-alt"></i> Sort';
                            });
                        }
                        if (list.nestable) {
                            list.nestable({maxDepth: 1}).on('change', function () {
                                const ids = list.nestable('serialize').map((i) => i.id);
                                $.post('{{ route('admin.__SNAKE__.reorder') }}', {ids: ids}, function () {
                                    window.toastr && window.toastr.success('Order updated');
                                });
                            });
                        }
                    });
                </script>
            @endpush
            BLADE
        ) : '';

        // Soft-delete snippets are built with the real class/route names (NOT Dummy
        // tokens) because strtr does not re-scan replaced text.
        $softRoutes = $soft ? sprintf(
            "\n    Route::controller(%sController::class)\n"
            . "        ->middleware(config('admin-core.permission.enabled') ? 'permission:delete-%s' : [])\n"
            . "        ->group(function () {\n"
            . "            Route::get('trash', 'trash')->name('trash');\n"
            . "            Route::put('restore/{id}', 'restore')->name('restore');\n"
            . "            Route::delete('forceDelete/{id}', 'forceDelete')->name('forceDelete');\n"
            . "        });",
            $class,
            $kebab,
        ) : '';

        $trashLink = $soft ? sprintf(
            "\n            <a href=\"{{ route('admin.%s.trash') }}\" class=\"btn btn-sm btn-secondary\">\n"
            . "                <i class=\"fas fa-trash\"></i> Trash\n"
            . '            </a>',
            $snakePlural,
        ) : '';

        $replace = [
            'DummyClasses' => $plural,
            'DummyClass' => $class,
            'dummyModels' => $snakePlural,
            'dummyModel' => $camel,
            'dummy-model' => $kebab,
            '__AC_FILLABLE__' => $fields->fillable(),
            '__AC_HIDDEN__' => $fields->hidden(),
            '__AC_PK__' => $fields->primaryKey(),
            '__AC_MODEL_TRAITS__' => $fields->modelTraits(),
            '__AC_MODEL_USES__' => $fields->modelUses(),
            '__AC_CASTS__' => $fields->casts(),
            '__AC_RELATIONS__' => $fields->relations(),
            '__AC_MODEL_BOOT__' => $fields->modelBoot(),
            '__AC_COLUMNS__' => $fields->migrationColumns(),
            '__AC_EXTRA_SCHEMA__' => $fields->extraSchema(),
            '__AC_ENCTYPE__' => $fields->enctype(),
            '__AC_SERVICE_USES__' => $fields->serviceUses(),
            '__AC_SERVICE_BODY__' => $fields->serviceBody(),
            '__AC_STORE_RULES__' => $fields->storeRules(),
            '__AC_UPDATE_RULES__' => $fields->updateRules(),
            '__AC_STORE_PREPARE__' => $fields->prepare(false),
            '__AC_UPDATE_PREPARE__' => $fields->prepare(true),
            '__AC_UPDATE_USES__' => $fields->updateUses(),
            '__AC_FORM__' => $fields->formFields(),
            '__AC_FORM_SCRIPTS__' => $fields->formScripts(),
            '__AC_THEAD__' => $fields->thead(),
            '__AC_COLS__' => $fields->columnsJs(),
            '__AC_EAGER__' => $fields->eager(),
            '__AC_GETDATA__' => $fields->getDataColumns(),
            '__AC_RAW__' => $fields->rawColumns(),
            '__AC_SHOW__' => $fields->showRows(),
            '__AC_FACTORY__' => $fields->factoryDefinition(),
            '__AC_SOFT_DELETES__' => $fields->softDeletesColumn(),
            '__AC_SOFT_ROUTES__' => $softRoutes,
            '__AC_TRASH_LINK__' => $trashLink,
            '__AC_SORT_COLUMN__' => $fields->sortColumn(),
            '__AC_SORT_ROUTES__' => $sortRoutes,
            '__AC_SORT_BUTTON__' => $sortButton,
            '__AC_SORT_PANEL__' => $sortPanel,
            '__AC_FILTER_TABS__' => $fields->filterTabs($snakePlural . '_table'),
            '__AC_TEST_FILES__' => $fields->testFilePayload(),
            '__AC_TEST_DELETE_ASSERT__' => $soft ? 'assertSoftDeleted($object)' : 'assertModelMissing($object)',
            '__AC_RESOURCE_FIELDS__' => $fields->resourceFields(),
            '__AC_API_SEARCHABLE__' => $fields->apiSearchable(),
            '__AC_API_SORTABLE__' => $fields->apiSortable(),
            '__AC_API_FILTERABLE__' => $fields->apiFilterable(),
        ];

        // Channels: web (default), api (--api adds it, --api-only swaps to it). The
        // shared core is always generated; reruns skip existing files, so a web-only
        // resource gains the API later via `--api` (and an api-only one gains the
        // web channel by re-running without --api-only).
        $api = $this->option('api') || $this->option('api-only');
        $web = ! $this->option('api-only');

        // Shared core — both channels sit on these.
        $files = [
            'model.stub' => app_path("Models/{$class}.php"),
            'service.stub' => app_path("Services/{$plural}/{$class}Service.php"),
            'store-request.stub' => app_path("Http/Requests/{$class}/Store{$class}Request.php"),
            'update-request.stub' => app_path("Http/Requests/{$class}/Update{$class}Request.php"),
            'factory.stub' => database_path("factories/{$class}Factory.php"),
            'seeder.stub' => database_path("seeders/{$class}Seeder.php"),
            'policy.stub' => app_path("Policies/{$class}Policy.php"),
        ];

        if ($web) {
            $files += [
                'controller.stub' => app_path("Http/Controllers/Backend/{$class}Controller.php"),
                'routes.stub' => base_path("routes/Web/Backend/Modules/{$snakePlural}.php"),
                'views/index.stub' => resource_path("views/backend/pages/{$snakePlural}/index.blade.php"),
                'views/show.stub' => resource_path("views/backend/pages/{$snakePlural}/show.blade.php"),
                'views/create.stub' => resource_path("views/backend/pages/{$snakePlural}/create.blade.php"),
                'views/edit.stub' => resource_path("views/backend/pages/{$snakePlural}/edit.blade.php"),
                'views/form.stub' => resource_path("views/backend/pages/{$snakePlural}/partials/form.blade.php"),
                'views/thead.stub' => resource_path("views/backend/pages/{$snakePlural}/partials/thead.blade.php"),
                'views/scripts.stub' => resource_path("views/backend/pages/{$snakePlural}/partials/scripts.blade.php"),
            ];
        }

        if ($soft && $web) {
            $files['views/trash.stub'] = resource_path("views/backend/pages/{$snakePlural}/trash.blade.php");
        }

        if ($this->option('tests')) {
            if ($web) {
                $files['tests.stub'] = base_path("tests/Feature/{$class}Test.php");
            } else {
                $this->warn('Skipped --tests: the generated feature test drives the web routes (re-run without --api-only to add them).');
            }
        }

        if ($api) {
            $files['api-resource.stub'] = app_path("Http/Resources/{$class}Resource.php");
            $files['api-controller.stub'] = app_path("Http/Controllers/Api/{$class}ApiController.php");
            $files['api-routes.stub'] = base_path("routes/Api/Modules/{$snakePlural}.php");
        }

        if ($this->option('migration')) {
            // Reuse an existing create migration so re-running never makes a duplicate
            // (timestamps differ, so a plain --force could not overwrite it otherwise).
            $existing = glob(base_path("database/migrations/*_create_{$snakePlural}_table.php")) ?: [];

            if ($existing && ! $this->option('force')) {
                $this->warn("Skipped migration: create_{$snakePlural}_table already exists (use --force to overwrite it).");
            } else {
                $files['migration.stub'] = $existing[0]
                    ?? base_path('database/migrations/' . date('Y_m_d_His') . "_create_{$snakePlural}_table.php");
            }
        }

        $stubBase = $this->stubPath();

        foreach ($files as $stub => $target) {
            if (File::exists($target) && ! $this->option('force')) {
                $this->warn('Skipped (exists): ' . $this->relative($target));
                continue;
            }
            File::ensureDirectoryExists(dirname($target));
            File::put($target, strtr(File::get("{$stubBase}/{$stub}"), $replace));
            $this->line('  <info>created</info> ' . $this->relative($target));
        }

        // One backed enum per enum field (App\Enums\PostStatus) — the single source
        // of truth its validation/cast/form/tabs/factory all reference.
        foreach ($fields->enumDefinitions() as $def) {
            $target = app_path("Enums/{$def['class']}.php");
            if (File::exists($target) && ! $this->option('force')) {
                $this->warn('Skipped (exists): ' . $this->relative($target));
                continue;
            }
            $cases = implode("\n", array_map(
                fn ($name, $value) => "    case {$name} = '{$value}';",
                array_keys($def['cases']),
                $def['cases'],
            ));
            File::ensureDirectoryExists(dirname($target));
            File::put($target, strtr(File::get("{$stubBase}/enum.stub"), [
                '__AC_ENUM_CLASS__' => $def['class'],
                '__AC_ENUM_CASES__' => $cases,
            ]));
            $this->line('  <info>created</info> ' . $this->relative($target));
        }

        $this->createPermissions($kebab, $plural);
        if ($web) {
            $this->registerMenuItem($plural, $snakePlural, $kebab);
        }

        $this->newLine();
        $this->info("Resource '{$class}' scaffolded.");
        if ($web) {
            $this->line("  Web:  /admin/{$snakePlural}   (name: admin.{$snakePlural}.*)");
        }
        if ($api) {
            $this->line("  API:  /api/{$snakePlural}   (name: api.{$snakePlural}.*)");
        }
        $this->line($web
            ? "  Run <info>php artisan migrate</info>, then visit /admin/{$snakePlural} — permissions are already granted to the admin role."
            : "  Run <info>php artisan migrate</info> — API routes are loaded from routes/Api/Modules and gated by the {$kebab} permissions.");

        return self::SUCCESS;
    }

    /**
     * Register the resource in the sidebar. Preferred: append a data entry to the
     * `menu` array in config/admin-core.php (rendered + permission-filtered by the
     * admin-core::sidebar-menu component). Falls back to injecting Blade into the
     * static sidebar for installs that predate the data-driven menu. Idempotent.
     */
    private function registerMenuItem(string $plural, string $snakePlural, string $kebab): void
    {
        $label = \Illuminate\Support\Str::headline($plural);
        $route = "admin.{$snakePlural}.index";
        $config = config_path('admin-core.php');

        if (File::exists($config)) {
            $contents = File::get($config);
            if (str_contains($contents, '// admin-core:menu')) {
                if (str_contains($contents, "'{$route}'")) {
                    return; // already in the menu — idempotent
                }
                $entry = "['label' => '{$label}', 'route' => '{$route}', 'icon' => 'bi bi-circle', 'can' => 'list-{$kebab}', 'match' => 'admin/{$snakePlural}*'],";
                File::put($config, str_replace('// admin-core:menu', $entry . "\n        // admin-core:menu", $contents));
                $this->line('  <info>menu</info> added "' . $label . '" to config/admin-core.php (run config:clear if you cache config)');

                return;
            }
        }

        $this->injectSidebarBladeLink($label, $snakePlural);
    }

    /** Legacy path: inject a nav `<li>` at the `{{-- admin-core:menu --}}` marker in the static sidebar. */
    private function injectSidebarBladeLink(string $label, string $snakePlural): void
    {
        $partial = resource_path('views/backend/partials/sidebar.blade.php');
        $layout = resource_path('views/backend/layouts/app.blade.php');
        $target = File::exists($partial) ? $partial : $layout;

        if (! File::exists($target)) {
            return;
        }

        $contents = File::get($target);
        $route = "route('admin.{$snakePlural}.index')";

        if (! str_contains($contents, 'admin-core:menu') || str_contains($contents, $route)) {
            return;
        }

        $themed = str_contains($contents, 'ac-nav'); // custom theme sidebar vs minimal layout

        $link = $themed
            ? "<li class=\"ac-nav-item\">\n"
                . "                    <a href=\"{{ {$route} }}\" class=\"ac-nav-link {{ request()->is('admin/{$snakePlural}*') ? 'active' : '' }}\">\n"
                . "                        <i class=\"bi bi-circle\"></i><span>{$label}</span>\n"
                . "                    </a>\n"
                . "                </li>\n                {{-- admin-core:menu --}}"
            : "<li class=\"nav-item\">\n"
                . "                <a href=\"{{ {$route} }}\" class=\"nav-link text-white\">\n"
                . "                    <i class=\"fas fa-circle me-2\"></i> {$label}\n"
                . "                </a>\n"
                . "            </li>\n            {{-- admin-core:menu --}}";

        File::put($target, str_replace('{{-- admin-core:menu --}}', $link, $contents));
        $this->line('  <info>menu</info> added "' . $label . '" to the sidebar');
    }

    /** Published stubs (base_path) win over the package's own, so projects can customise them. */
    private function stubPath(): string
    {
        $published = base_path('stubs/admin-core');

        return File::isDirectory($published) ? $published : __DIR__ . '/../../stubs';
    }

    /**
     * Reconstruct the `--fields` DSL from an existing resource so a channel can be added
     * without re-typing it. Field names + order come from the model's `$fillable`; column
     * types come from the migration(s) (authoritative — catches integer/time the model
     * doesn't cast), upgraded to enum/password where `casts()` reveals it (both are stored
     * as plain string columns). `belongsToMany` relations are read off the model. Returns
     * null when there's nothing to infer. Upload (image/file) columns read back as plain
     * strings — the migration can't tell them apart — so pass --fields for those.
     */
    private function inferFieldsFromModel(string $class, string $table): ?string
    {
        $src = File::get(app_path("Models/{$class}.php"));

        if (! preg_match('/protected \$fillable = \[(.*?)\];/s', $src, $m)) {
            return null;
        }
        preg_match_all("/'([^']+)'/", $m[1], $names);
        $names = $names[1];
        if (! $names) {
            return null;
        }

        // column => cast expression (e.g. status => \App\Enums\PostStatus::class, secret => 'hashed')
        $casts = [];
        if (preg_match('/function casts\(\): array.*?return \[(.*?)\];/s', $src, $cm)) {
            preg_match_all("/'([^']+)'\s*=>\s*([^\n,]+)/", $cm[1], $cc, PREG_SET_ORDER);
            foreach ($cc as $row) {
                $casts[$row[1]] = trim($row[2]);
            }
        }

        $columns = $this->migrationColumnTypes($table); // column => DSL type from $table->TYPE(...)

        $tokens = [];
        foreach ($names as $name) {
            $tokens[] = $name . ':' . $this->inferType($name, $casts[$name] ?? null, $columns[$name] ?? null);
        }

        // belongsToMany lives on the model as a typed relation, not in $fillable.
        if (preg_match_all('/public function (\w+)\(\): BelongsToMany/', $src, $mm)) {
            foreach ($mm[1] as $relation) {
                $tokens[] = $relation . ':belongsToMany';
            }
        }

        return implode(', ', $tokens); // always ≥1 token here ($names was non-empty)
    }

    /** Map one fillable column back to a DSL type from its cast (enum/password) then its migration column type. */
    private function inferType(string $name, ?string $cast, ?string $migrationType): string
    {
        // enum + password are plain columns; only the cast reveals what they really are.
        if ($cast !== null) {
            if (str_contains($cast, '::class') && ($cases = $this->enumCasesDsl($cast)) !== null) {
                return 'enum:' . $cases;
            }
            if (strtolower(trim($cast, "'\"")) === 'hashed') {
                return 'password';
            }
        }

        // The migration is authoritative for everything else (incl. integer/time, which aren't cast).
        if ($migrationType !== null) {
            return $migrationType;
        }

        // No migration column (hand-added field?) — fall back to the cast, then the name.
        $c = $cast !== null ? strtolower(trim($cast, "'\"")) : '';

        return match (true) {
            $c === 'boolean', $c === 'bool' => 'boolean',
            $c === 'date' => 'date',
            str_starts_with($c, 'datetime'), str_starts_with($c, 'immutable_datetime') => 'datetime',
            str_starts_with($c, 'decimal') => 'decimal',
            $c === 'array', $c === 'json', str_starts_with($c, 'collection') => 'json',
            $c === 'integer', $c === 'int' => 'integer',
            default => str_ends_with($name, '_id') ? 'foreign' : 'string',
        };
    }

    /** column => DSL type, scanned from every migration that touches the table (create + add_*). */
    private function migrationColumnTypes(string $table): array
    {
        $map = [];
        foreach (glob(base_path("database/migrations/*_{$table}_table.php")) ?: [] as $file) {
            preg_match_all('/\$table->(\w+)\(\s*[\'"](\w+)[\'"]/', File::get($file), $cols, PREG_SET_ORDER);
            foreach ($cols as $row) {
                $dsl = match ($row[1]) {
                    'string', 'char' => 'string',
                    'text', 'longText', 'mediumText', 'tinyText' => 'text',
                    'integer', 'bigInteger', 'unsignedBigInteger', 'unsignedInteger',
                    'smallInteger', 'unsignedSmallInteger', 'tinyInteger', 'unsignedTinyInteger' => 'integer',
                    'decimal', 'unsignedDecimal', 'float', 'double' => 'decimal',
                    'boolean' => 'boolean',
                    'date' => 'date',
                    'dateTime', 'dateTimeTz', 'timestamp', 'timestampTz' => 'datetime',
                    'time', 'timeTz' => 'time',
                    'json', 'jsonb' => 'json',
                    'foreignId', 'foreignUuid' => 'foreign',
                    default => null, // id/uuid/timestamps/softDeletes/etc. — not user fields
                };
                if ($dsl !== null) {
                    $map[$row[2]] = $dsl;
                }
            }
        }

        return $map;
    }

    /** Pipe-joined enum values read from the backed enum a cast points at, or null. */
    private function enumCasesDsl(string $castExpr): ?string
    {
        if (! preg_match('/Enums\\\\(\w+)::class/', $castExpr, $m)) {
            return null;
        }
        $path = app_path("Enums/{$m[1]}.php");
        if (! File::exists($path)) {
            return null;
        }
        preg_match_all("/case \w+ = '([^']+)'/", File::get($path), $cases);

        return $cases[1] ? implode('|', $cases[1]) : null;
    }

    private function createPermissions(string $kebab, string $plural): void
    {
        if (! config('admin-core.permission.enabled') || ! Schema::hasTable('permissions')) {
            return;
        }

        $model = config('admin-core.permission.model', \Spatie\Permission\Models\Permission::class);
        $names = array_map(fn ($action) => "{$action}-{$kebab}", ['list', 'create', 'edit', 'delete']);

        foreach ($names as $name) {
            $model::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // File the permissions under a "{Plural} Management" group so the Role-edit
        // permission tree stays organised (only when the group-permission feature exists).
        $grouped = '';
        if (Schema::hasTable('group_permissions') && Schema::hasColumn('permissions', 'group_id')) {
            $groupName = Str::headline($plural) . ' Management';
            $groupId = DB::table('group_permissions')->where('name', $groupName)->value('id');
            if (! $groupId) {
                $parentId = DB::table('group_permissions')->where('name', 'All')->value('id');
                $row = [
                    'name' => $groupName,
                    'parent_id' => $parentId,
                    'sort' => (int) DB::table('group_permissions')->where('parent_id', $parentId)->max('sort') + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                // Raw insert bypasses the model's HasPublicUuid hook, so fill the
                // public uuid ourselves when the hybrid-key column is present.
                if (Schema::hasColumn('group_permissions', 'uuid')) {
                    $row['uuid'] = (string) Str::uuid7();
                }
                $groupId = DB::table('group_permissions')->insertGetId($row);
            }
            DB::table('permissions')->whereIn('name', $names)->update(['group_id' => $groupId]);
            $grouped = " under '{$groupName}'";
        }

        // Grant the new permissions to the super role so the resource works right
        // away — no need to re-run AccessSeeder after every admin-core:make.
        $granted = '';
        $roleName = config('admin-core.permission.super_role', 'admin');
        if ($roleName && Schema::hasTable('roles')) {
            $roleModel = config('admin-core.permission.role_model', \Spatie\Permission\Models\Role::class);
            $role = $roleModel::where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($names);
                $granted = " (granted to '{$roleName}')";
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->line("  <info>permissions</info> list/create/edit/delete-{$kebab}{$grouped}{$granted}");
    }

    private function relative(string $path): string
    {
        return Str::after($path, base_path() . DIRECTORY_SEPARATOR);
    }
}
