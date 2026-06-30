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
    protected $signature = 'admin-core:make {name? : The resource name, e.g. Product}
                            {--fields= : Field DSL, e.g. "name:string, price:decimal?, category_id:foreign, parent_id:foreign:categories"}
                            {--list-fields : Print the field types + modifiers the --fields DSL accepts, then exit}
                            {--uuid : Use a UUID primary key (and UUID foreign keys)}
                            {--no-uuid : Force an auto-increment key even if config enables uuid}
                            {--soft-deletes : Add soft deletes + a trash/restore screen}
                            {--no-soft-deletes : Skip soft deletes even if config enables them}
                            {--audit : Log created/updated/deleted activity for this resource}
                            {--sortable : Add a drag-and-drop ordering column (sort) + reorder list}
                            {--unique=* : Composite unique over multiple columns, e.g. --unique="order_id,product_id" (repeatable). Use the ^ modifier for a single column.}
                            {--migration : Also generate a create migration}
                            {--tests : Also generate a CRUD feature test (best paired with --migration)}
                            {--api : Also generate a JSON API (resource + controller + apiResource routes)}
                            {--api-only : Generate ONLY the JSON API (no web controller/views/routes) — add the web channel later by re-running without it}
                            {--read-only : A read-only resource — list, show, export + filters/totals, but NO create/edit/delete/import (a report). Only the list permission is seeded.}
                            {--menu= : Register the sidebar link in a named portal menu (config admin-core.menus.NAME) instead of the default}
                            {--guard= : Auth guard for the permissions + route gates (multi-portal, e.g. merchant). Defaults to the app guard.}
                            {--portal= : Generate the resource INTO a portal (created by admin-core:portal): routes under routes/Portal/Modules with NAME. route-names, its guard + menu. Implies --guard/--menu=NAME.}
                            {--widget : Also scaffold a count stat widget (app/Dashboard) for the dashboard framework}
                            {--force : Overwrite existing files}';

    protected $description = 'Scaffold a full admin-core CRUD resource (model, service, controller, requests, routes, views, permissions).';

    public function handle(): int
    {
        // `--list-fields` is a reference lookup, not a generation — print the DSL catalog and stop
        // (so it works without a resource name).
        if ($this->option('list-fields')) {
            $this->showFieldTypes();

            return self::SUCCESS;
        }

        $name = $this->argument('name');
        if (! is_string($name) || trim($name) === '') {
            $this->error('Missing the resource name, e.g. `admin-core:make Product`. (Run with --list-fields to see the field types.)');

            return self::FAILURE;
        }

        $class = Str::studly(Str::singular($name));
        $plural = Str::plural($class);
        $camel = Str::camel($class);
        $snakePlural = Str::snake(Str::pluralStudly($class));
        $kebab = Str::kebab($class);

        $uuid = $this->option('no-uuid')
            ? false
            : ($this->option('uuid') || (bool) config('admin-core.generator.uuid', false));

        $soft = $this->option('no-soft-deletes')
            ? false
            : ($this->option('soft-deletes') || (bool) config('admin-core.generator.soft_deletes', false));

        $audit = $this->option('audit') || (bool) config('admin-core.generator.audit', false);
        $sortable = (bool) $this->option('sortable');

        // A read-only resource (a report): list + show + export + filters/totals, no create/edit/delete/import.
        // It's a WRITE-less channel, so soft-deletes + sortable (both write features) don't apply — drop them.
        $readOnly = (bool) $this->option('read-only');
        if ($readOnly) {
            if ($soft || $sortable) {
                $this->warn('  --read-only ignores --soft-deletes/--sortable (both add write actions).');
            }
            $soft = false;
            $sortable = false;
        }

        // Multi-portal. --portal=merchant is the one-flag way: it routes the resource INTO that
        // portal (its Modules dir, `merchant.` route-names, the controller's route prefix, its
        // menu) AND scopes permissions/gates to its guard. --guard/--menu remain low-level
        // overrides. $guard is used for permission creation; the route suffix/arg are only
        // emitted when a non-default guard is in play, so plain admin resources stay clean.
        $portal = $this->option('portal') ? Str::kebab($this->option('portal')) : null;
        $guardOpt = $this->option('guard') ?: $portal;
        $menuName = $this->option('menu') ?: $portal;
        $guard = $guardOpt ?: config('admin-core.permission.guard', config('auth.defaults.guard', 'web'));
        $permSuffix = $guardOpt ? ",{$guardOpt}" : '';
        $crudGuardArg = $guardOpt ? ", '{$guardOpt}'" : '';
        // Read-only codegen: tell Route::crud to skip the write routes, drop the FormRequest import/assigns from
        // the controller, and omit the import/bulkDelete routes — so a read-only resource has no write surface.
        $crudReadOnlyArg = $readOnly ? ', readOnly: true' : '';
        $requestImports = $readOnly ? '' :
            "use App\\Http\\Requests\\{$class}\\Store{$class}Request;\nuse App\\Http\\Requests\\{$class}\\Update{$class}Request;\n";
        $requestAssigns = $readOnly ? '' :
            "\n        \$this->storeRequest = Store{$class}Request::class;\n        \$this->updateRequest = Update{$class}Request::class;";
        // The import/bulkDelete routes (create + delete permissions) — dropped for a read-only resource.
        $writeRoutes = $readOnly ? '' : <<<PHP
                Route::get('import-template', 'importTemplate')->name('importTemplate')
                    ->middleware(config('admin-core.permission.enabled') ? 'permission:create-{$kebab}{$permSuffix}' : []);
                Route::post('import', 'import')->name('import')
                    ->middleware(config('admin-core.permission.enabled') ? 'permission:create-{$kebab}{$permSuffix}' : []);
                Route::post('bulkDelete', 'bulkDelete')->name('bulkDelete')
                    ->middleware(config('admin-core.permission.enabled') ? 'permission:delete-{$kebab}{$permSuffix}' : []);

        PHP;
        // The JSON API's write routes (store/update/destroy) — dropped for a read-only resource, so --read-only
        // --api yields a read-only API (index + show) instead of a full CRUD one backed by missing requests.
        $apiWriteRoutes = $readOnly ? '' : <<<PHP
                Route::post('/', [{$class}ApiController::class, 'store'])->name('store')->middleware(\$gate('create'));
                Route::put('{id}', [{$class}ApiController::class, 'update'])->name('update')->middleware(\$gate('edit'));
                Route::delete('{id}', [{$class}ApiController::class, 'destroy'])->name('destroy')->middleware(\$gate('delete'));

        PHP;
        // Route-name prefix + module dir: a portal resource lives under `merchant.` in
        // routes/Merchant/Modules; otherwise the configured admin prefix + the admin dir.
        $routeNs = $portal ? "{$portal}." : config('admin-core.route.name_prefix', 'admin.');
        $routePrefixLine = $portal ? "\n        \$this->routePrefix = '{$routeNs}';" : '';
        // A guarded/portal resource also pins the auth guard so the generated views' permission checks
        // (the @can action buttons + the row-action dropdown) resolve against that guard's user, not the default.
        $routePrefixLine .= $guardOpt ? "\n        \$this->guard = '{$guardOpt}';" : '';
        $moduleDir = $portal ? 'routes/' . Str::studly($portal) . '/Modules' : 'routes/Web/Backend/Modules';
        // A portal resource extends that portal's layout (merchant.layout) so it renders inside the
        // portal chrome (its sidebar/guard), not the admin layout.
        $layoutView = $portal ? "{$portal}.layout" : 'backend.layouts.app';

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

        // Brand-new resource with no --fields and nothing to infer: build the field list
        // interactively (Laravel-style prompt-for-missing-input). A no-op in non-interactive
        // runs (tests/CI/scripts), which fall through to FieldSet's single default `name` field.
        if (trim((string) $fieldsDsl) === '' && ! File::exists(app_path("Models/{$class}.php"))) {
            $fieldsDsl = $this->promptForFields($class) ?: $fieldsDsl;
        }

        // Composite unique groups from --unique="a,b" (repeatable) — each a comma-separated column list.
        $uniqueGroups = collect((array) $this->option('unique'))
            ->map(fn ($g) => array_values(array_filter(array_map('trim', explode(',', (string) $g)))))
            ->filter(fn ($g) => $g !== [])
            ->all();

        try {
            $fields = (new FieldSet($fieldsDsl))
                ->setTable($snakePlural)
                ->setUuid($uuid)
                ->setSoftDeletes($soft)
                ->setAudit($audit)
                ->setSortable($sortable)
                ->setClass($class)
                ->setUniqueGroups($uniqueGroups);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sortRoutes = $sortable ? sprintf(
            "\n    Route::post('reorder', [%sController::class, 'reorder'])->name('reorder')\n"
            . "        ->middleware(config('admin-core.permission.enabled') ? 'permission:edit-%s%s' : []);",
            $class,
            $kebab,
            $permSuffix,
        ) : '';

        // --sortable adds a "Sort" toggle button + a drag-and-drop panel to the
        // normal DataTable index (the table stays; sorting is an opt-in mode).
        $sortButton = $sortable
            ? "\n            <button type=\"button\" id=\"toggle-sort\" class=\"btn btn-sm btn-outline-primary\">\n"
                . "                <i class=\"bi bi-grip-vertical\"></i> Sort\n            </button>"
            : '';

        $sortPanel = $sortable ? str_replace(
            ['__CLASS__', '__SNAKE__', '__AC_RNS__'],
            [$class, $snakePlural, $routeNs],
            <<<'BLADE'

            <div id="sort-panel" class="d-none mt-2">
                @php($sortItems = \App\Models\__CLASS__::orderBy('sort')->limit(500)->get())
                <div class="alert alert-info py-2">Drag rows to reorder — changes save automatically.</div>
                <div class="dd nestable-lists" id="__SNAKE___sortable">
                    <ol class="dd-list">
                        @forelse ($sortItems as $sortItem)
                            <li class="dd-item" data-id="{{ $sortItem->getRouteKey() }}">
                                <div class="dd-handle">{{ ac_localize($sortItem->name) ?: $sortItem->id }}</div>
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
                                    ? '<i class="bi bi-check-lg"></i> Done'
                                    : '<i class="bi bi-grip-vertical"></i> Sort';
                            });
                        }
                        if (list.nestable) {
                            list.nestable({maxDepth: 1}).on('change', function () {
                                const ids = list.nestable('serialize').map((i) => i.id);
                                $.post('{{ route('__AC_RNS____SNAKE__.reorder') }}', {ids: ids}, function () {
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
            . "        ->middleware(config('admin-core.permission.enabled') ? 'permission:delete-%s%s' : [])\n"
            . "        ->group(function () {\n"
            . "            Route::get('trash', 'trash')->name('trash');\n"
            . "            Route::put('restore/{id}', 'restore')->name('restore');\n"
            . "            Route::delete('forceDelete/{id}', 'forceDelete')->name('forceDelete');\n"
            . "            Route::post('bulkRestore', 'bulkRestore')->name('bulkRestore');\n"
            . "            Route::post('bulkForceDelete', 'bulkForceDelete')->name('bulkForceDelete');\n"
            . "        });",
            $class,
            $kebab,
            $permSuffix,
        ) : '';

        $trashLink = $soft ? sprintf(
            "\n            <a href=\"{{ route('%s%s.trash') }}\" class=\"btn btn-sm btn-secondary\">\n"
            . "                <i class=\"bi bi-trash\"></i> Trash\n"
            . '            </a>',
            $routeNs,
            $snakePlural,
        ) : '';

        // Append the related name to CSV exports for each belongsTo (readable next to the FK id).
        $exportRelations = $fields->exportRelations();
        $exportRelationsLine = $exportRelations === '' ? '' : "\n        \$this->exportRelations = [{$exportRelations}];";
        // Allowlist this resource's foreign keys as Select2 filters so a cascading child select can narrow by them.
        $selectFilters = $fields->foreignColumns();
        $selectFiltersLine = $selectFilters === [] ? '' : "\n        \$this->selectFilters = ['" . implode("', '", $selectFilters) . "'];";
        // Export columns as a value => label literal for <x-admin-core::export-menu> (which renders the
        // checkboxes — all checked = export everything; unticking narrows ?columns[]).
        $exportFieldsLiteral = '[' . collect($fields->exportFields())
            ->map(fn ($label, $col) => "'{$col}' => '{$label}'")
            ->implode(', ') . ']';

        // Base class the generated model extends. Default is Eloquent's Model; a host can point
        // config('admin-core.generator.base_model') at its own base (e.g. App\Models\BaseModel that
        // `use`s shared traits/casts) to DRY up many models.
        $baseModel = ltrim((string) (config('admin-core.generator.base_model') ?: \Illuminate\Database\Eloquent\Model::class), '\\');

        $replace = [
            '__AC_MODEL_BASE__' => class_basename($baseModel),
            '__AC_MODEL_BASE_IMPORT__' => "use {$baseModel};",
            'DummyClasses' => $plural,
            'DummyClass' => $class,
            'dummyModels' => $snakePlural,
            'dummyModel' => $camel,
            'dummy-model' => $kebab,
            '__AC_FILLABLE__' => $fields->fillable(),
            '__AC_HIDDEN__' => $fields->hidden(),
            '__AC_CRUD_GUARD__' => $crudGuardArg,
            '__AC_CRUD_RO__' => $crudReadOnlyArg,
            '__AC_USE_REQUESTS__' => $requestImports,
            '__AC_ASSIGN_REQUESTS__' => $requestAssigns,
            '__AC_WRITE_ROUTES__' => $writeRoutes,
            '__AC_API_WRITE_ROUTES__' => $apiWriteRoutes,
            '__AC_PERM_GUARD__' => $permSuffix,
            '__AC_RNS__' => $routeNs,
            '__AC_LAYOUT__' => $layoutView,
            '__AC_ROUTE_PREFIX__' => $routePrefixLine,
            '__AC_EXPORT_RELATIONS__' => $exportRelationsLine,
            '__AC_SELECT_FILTERS__' => $selectFiltersLine,
            '__AC_EXPORT_FIELDS__' => $exportFieldsLiteral,
            '__AC_PK__' => $fields->primaryKey(),
            '__AC_MODEL_TRAITS__' => $fields->modelTraits(),
            '__AC_MODEL_USES__' => $fields->modelUses(),
            '__AC_CASTS__' => $fields->casts(),
            '__AC_RELATIONS__' => $fields->relations(),
            '__AC_APPENDS__' => $fields->appends(),
            '__AC_ACCESSORS__' => $fields->accessors(),
            '__AC_LIST_FILTERS_METHOD__' => $fields->listFiltersMethod(),
            '__AC_LIST_AGGREGATES_METHOD__' => $fields->listAggregatesMethod(),
            '__AC_MODEL_BOOT__' => $fields->modelBoot(),
            '__AC_COLUMNS__' => $fields->migrationColumns(),
            '__AC_EXTRA_SCHEMA__' => $fields->extraSchema(),
            '__AC_UNIQUE__' => $fields->uniqueConstraints(),
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
            '__AC_COLS_PHP__' => $fields->columnsConfig(),
            '__AC_AGGS_ATTR__' => $fields->hasListAggregates()
                ? "\n        :aggregates=\"['" . implode("', '", $fields->aggregateColumns()) . "']\""
                : '',
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
            '__AC_API_WITH__' => $fields->apiWith(),
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
            'factory.stub' => database_path("factories/{$class}Factory.php"),
            'seeder.stub' => database_path("seeders/{$class}Seeder.php"),
            'policy.stub' => app_path("Policies/{$class}Policy.php"),
        ];

        // FormRequests validate writes — a read-only resource has none.
        if (! $readOnly) {
            $files['store-request.stub'] = app_path("Http/Requests/{$class}/Store{$class}Request.php");
            $files['update-request.stub'] = app_path("Http/Requests/{$class}/Update{$class}Request.php");
        }

        if ($web) {
            $files += [
                'controller.stub' => app_path("Http/Controllers/Backend/{$class}Controller.php"),
                'routes.stub' => base_path("{$moduleDir}/{$snakePlural}.php"),
                'views/index.stub' => resource_path("views/backend/pages/{$snakePlural}/index.blade.php"),
                'views/show.stub' => resource_path("views/backend/pages/{$snakePlural}/show.blade.php"),
                'views/thead.stub' => resource_path("views/backend/pages/{$snakePlural}/partials/thead.blade.php"),
                // No scripts.blade.php — the shared datatable.js drives the table from the
                // data-table component's :columns config (see views/index.stub).
            ];
            // Create/edit forms are write surfaces — skipped for a read-only resource.
            if (! $readOnly) {
                $files['views/create.stub'] = resource_path("views/backend/pages/{$snakePlural}/create.blade.php");
                $files['views/edit.stub'] = resource_path("views/backend/pages/{$snakePlural}/edit.blade.php");
                $files['views/form.stub'] = resource_path("views/backend/pages/{$snakePlural}/partials/form.blade.php");
            }
        }

        if ($soft && $web) {
            $files['views/trash.stub'] = resource_path("views/backend/pages/{$snakePlural}/trash.blade.php");
        }

        if ($this->option('tests')) {
            if (! $web) {
                $this->warn('Skipped --tests: the generated feature test drives the web routes (re-run without --api-only to add them).');
            } elseif ($readOnly) {
                $this->warn('Skipped --tests: the generated test drives create/edit/delete, which a --read-only resource has not — write a read-only test by hand.');
            } elseif ($guardOpt) {
                // The scaffold authenticates a default App\Models\User on the web guard; a guard/portal
                // resource is gated on '{$guardOpt}' (and a portal has its own user model), so the test
                // would 403. We can't know the portal's user factory — point the user at writing it.
                $this->warn("Skipped --tests: the generated test assumes the default admin user on the web guard. "
                    . "A '{$guardOpt}'-guard resource needs a test using that guard's user model + "
                    . "actingAs(\$user, '{$guardOpt}') and permissions on the '{$guardOpt}' guard — write it by hand.");
            } else {
                $files['tests.stub'] = base_path("tests/Feature/{$class}Test.php");
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

        // One row-partial per hasMany field — the master-detail repeater's row (a generated starting point).
        if ($web) {
            foreach ($fields->hasManyRowPartials() as $field => $content) {
                $target = resource_path("views/backend/pages/{$snakePlural}/partials/{$field}-row.blade.php");
                if (File::exists($target) && ! $this->option('force')) {
                    $this->warn('Skipped (exists): ' . $this->relative($target));
                    continue;
                }
                File::ensureDirectoryExists(dirname($target));
                File::put($target, $content);
                $this->line('  <info>created</info> ' . $this->relative($target));
            }
        }

        $this->createPermissions($kebab, $plural, $guard, $readOnly);
        if ($web) {
            $this->registerMenuItem($plural, $snakePlural, $kebab, $menuName, $routeNs);
        }
        if ($web && $this->option('widget')) {
            $this->registerDashboardWidget($class, $plural, $snakePlural, $kebab, $routeNs);
        }
        if ($api) {
            $this->ensureApiModulesLoaded();
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

        // A hasMany child is a separate resource this command doesn't scaffold. Warn when its model is missing —
        // a rollup makes this urgent (its accessor dereferences the relation on every list/show/API render, so a
        // missing child class 500s the page until you generate it).
        $hasRollup = collect($fields->fields())->contains(fn ($f) => $f['type'] === 'rollup');
        foreach ($fields->fields() as $f) {
            if ($f['type'] === 'hasMany' && ! class_exists('\\App\\Models\\' . $f['relModel'])) {
                $this->warn("  child model App\\Models\\{$f['relModel']} doesn't exist yet — generate it "
                    . "(`admin-core:make {$f['relModel']} …`) so the '{$f['relation']}' relation"
                    . ($hasRollup ? " + its rollup total work (the list/show/API render it on every row)." : ' works.'));
            }
        }

        // A per-record money currency column declared AFTER its money field fills second on create, so the
        // amount is parsed with the default currency's decimals. Warn to declare the currency column first.
        foreach ($fields->moneyCurrencyColumnsDeclaredLate() as $money => $col) {
            $this->warn("  money '{$money}' reads its currency from '{$col}', declared after it — on create the "
                . "amount is parsed with the default currency. Declare '{$col}' before '{$money}'.");
        }

        // A composite unique that includes a system / write-once column can't be form-validated (its value
        // isn't submitted) — the DB constraint enforces it, so a duplicate surfaces as a DB error, not a 422.
        foreach ($fields->uniqueGroupsWithoutFormValidation() as $group) {
            $this->warn('  composite unique [' . implode(', ', $group) . '] is enforced by the DB constraint only '
                . '(a system / write-once member isn\'t in the form to validate) — a duplicate surfaces as a database error.');
        }

        return self::SUCCESS;
    }

    /**
     * Make sure routes/api.php loads the generated API modules. Without this an `--api` resource's
     * route file in routes/Api/Modules just sits there (only `install --api-auth` wired the loader),
     * so /api/<resource> 404s. Uses the same marker as the installer, so it stays idempotent across
     * both. If routes/api.php doesn't exist yet, point the user at enabling API routing first.
     */
    private function ensureApiModulesLoaded(): void
    {
        $api = base_path('routes/api.php');

        if (! File::exists($api)) {
            // install:api creates a *bare* routes/api.php — it doesn't wire the module loader, and nothing
            // back-fills it later. So be explicit: enable API routing, then re-run to wire the loader.
            $this->warn('  --api: no routes/api.php yet (Laravel 11+ omits it). Run `php artisan install:api` '
                . 'to enable API routing, then re-run this command to load routes/Api/Modules — or run '
                . '`php artisan admin-core:install --api-auth`, which wires both. (Sanctum auth also needs the '
                . 'HasApiTokens trait on App\\Models\\User.)');

            return;
        }

        if (str_contains(File::get($api), 'Api/Modules')) {
            return; // loader already present (a prior --api resource or --api-auth)
        }

        File::append($api, <<<'PHP'

// >>> admin-core:api-modules
// API modules generated with `admin-core:make … --api`.
foreach (glob(__DIR__ . '/Api/Modules/*.php') ?: [] as $apiModule) {
    require $apiModule;
}
// <<< admin-core:api-modules

PHP);
        $this->line('  <info>wired</info> routes/api.php to load API modules from routes/Api/Modules');
    }

    /**
     * Register the resource in the sidebar. Preferred: append a data entry to the
     * `menu` array in config/admin-core.php (rendered + permission-filtered by the
     * admin-core::sidebar-menu component). Falls back to injecting Blade into the
     * static sidebar for installs that predate the data-driven menu. Idempotent.
     */
    /**
     * Scaffold a count stat widget (app/Dashboard/{Class}Widget) for the dashboard framework — how many of
     * this resource were created in the active range, with a trend + drill-down + permission. The user just
     * registers it in config('admin-core.dashboard.widgets'). Idempotent.
     */
    private function registerDashboardWidget(string $class, string $plural, string $snakePlural, string $kebab, string $routeNs): void
    {
        $path = app_path("Dashboard/{$class}Widget.php");
        if (File::exists($path) && ! $this->option('force')) {
            $this->line('  <comment>exists</comment>  ' . $this->relative($path));

            return;
        }

        $contents = strtr(File::get(__DIR__ . '/../../stubs/dashboard/resource-widget.stub'), [
            '{{ class }}' => $class,
            '{{ title }}' => $plural,
            '{{ route }}' => "{$routeNs}{$snakePlural}.index",
            '{{ kebab }}' => $kebab,
        ]);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        $this->line('  <info>widget</info>  ' . $this->relative($path));
        $this->line("    register it in <comment>config('admin-core.dashboard.widgets')</comment>: \\App\\Dashboard\\{$class}Widget::class");
    }

    private function registerMenuItem(string $plural, string $snakePlural, string $kebab, ?string $menu = null, string $routeNs = 'admin.'): void
    {
        $label = \Illuminate\Support\Str::headline($plural);
        $this->registerMenuLabelTranslation($label);
        $route = "{$routeNs}{$snakePlural}.index";

        // Database-driven menu: insert a menu_items row so the resource shows in the sidebar immediately.
        // Without this, a `menu_source=database` install only got the config-menu edit below — which isn't
        // the active source — so generated resources never appeared. Default menu only (named portals use
        // the config menu); the config edit still runs as the seed for `admin-core:menu:import`.
        $dbMenu = $menu === null
            && config('admin-core.menu_source') === 'database'
            && \Illuminate\Support\Facades\Schema::hasTable('menu_items');
        if ($dbMenu) {
            $this->addDatabaseMenuItem($label, $route, $kebab, rtrim($routeNs, '.') . "/{$snakePlural}*");
        }

        $config = config_path('admin-core.php');
        // Named portals append at `// admin-core:menu:<name>`; the default menu at `// admin-core:menu`.
        // The default marker is a *prefix* of the named ones, so match it with a boundary
        // (not followed by `:` or a word char) to avoid clobbering `…:merchant`.
        $marker = $menu !== null ? "// admin-core:menu:{$menu}" : '// admin-core:menu';
        $markerRe = $menu !== null
            ? '/\/\/ admin-core:menu:' . preg_quote($menu, '/') . '\b/'
            : '/\/\/ admin-core:menu(?![:\w])/';

        if (File::exists($config)) {
            $contents = File::get($config);
            if (preg_match($markerRe, $contents)) {
                // Idempotency check ignores block comments — the config docblock shows an example
                // entry (['route' => 'admin.products.index', …]), and a literal str_contains would
                // see that and wrongly skip a real "Product" resource. Strip /* … */ first.
                $codeOnly = preg_replace('/\/\*.*?\*\//s', '', $contents);
                if (str_contains($codeOnly, "'{$route}'")) {
                    return; // already in the menu — idempotent
                }
                $urlPrefix = rtrim($routeNs, '.');
                $entry = "['label' => '{$label}', 'route' => '{$route}', 'icon' => 'bi bi-circle', 'can' => 'list-{$kebab}', 'match' => '{$urlPrefix}/{$snakePlural}*'],";
                $contents = preg_replace_callback($markerRe, fn () => $entry . "\n        {$marker}", $contents, 1);
                File::put($config, $contents);
                $this->line('  <info>menu</info> added "' . $label . '" to config/admin-core.php (run config:clear if you cache config)');

                return;
            }
        }

        // A named portal menu lives in config only — never fall back to the default Blade
        // sidebar (that would put the resource in the wrong portal). Tell the user instead.
        if ($menu !== null) {
            $this->warn("  menu: couldn't add to the '{$menu}' menu — add a `{$marker}` marker inside config('admin-core.menus.{$menu}') in config/admin-core.php (publish the config first if it's missing).");

            return;
        }

        // A database menu already got its row above; don't also inject the legacy Blade sidebar link.
        if (! $dbMenu) {
            $this->injectSidebarBladeLink($label, $snakePlural);
        }
    }

    /**
     * Seed the new menu label into the host app's locale JSON files so __($label) is translatable.
     * Appends "Label" => "Label" to lang/<locale>.json for every configured non-default locale whose file
     * already exists — never creating a file (a no-op on installs without JSON lang files) and never
     * overwriting an existing value. Idempotent.
     */
    private function registerMenuLabelTranslation(string $label): void
    {
        $default = (string) config('admin-core.translation.default', config('app.fallback_locale', 'en'));
        foreach (array_keys((array) config('admin-core.translation.locales', [])) as $locale) {
            if (! is_string($locale) || $locale === $default) {
                continue; // skip the source locale (falls back to the key) and any non-string code (list-shaped config)
            }
            $path = lang_path("{$locale}.json");
            if (! File::exists($path)) {
                continue; // don't create lang files the host hasn't opted into
            }
            $messages = json_decode(File::get($path), true);
            if (! is_array($messages) || array_key_exists($label, $messages)) {
                continue; // malformed, or already present — leave it alone
            }
            $messages[$label] = $label; // English placeholder; a translator / admin-core:translate fills it in
            ksort($messages);
            File::put($path, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            $this->line("  <info>i18n</info> seeded \"{$label}\" into lang/{$locale}.json (translate it for {$locale})");
        }
    }

    /** Insert a menu_items row for a generated resource (database-driven menu). Idempotent by route. */
    private function addDatabaseMenuItem(string $label, string $route, string $kebab, string $match): void
    {
        $model = \Ngos\AdminCore\Models\MenuItem::class;
        if ($model::where('route', $route)->exists()) {
            return; // already in the menu — idempotent
        }
        $model::create([
            'label' => $label,
            'route' => $route,
            'icon' => 'bi bi-circle',
            'match' => $match,
            'permission' => "list-{$kebab}",
            'sort' => (int) $model::max('sort') + 1,
            'is_active' => true,
        ]);
        $this->line('  <info>menu</info> added "' . $label . '" to the menu_items table');
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
                . "                    <i class=\"bi bi-circle me-2\"></i> {$label}\n"
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

    /**
     * Build the --fields DSL interactively when none was given for a brand-new resource. Mirrors how
     * Laravel's own generators prompt for missing input: enter a name, pick a type, answer
     * nullable/unique, repeat until the name is left blank. Returns the assembled DSL — or '' in a
     * non-interactive run (tests/CI/scripts) or when nothing is added, so the caller keeps the existing
     * behaviour (FieldSet's single default `name` field).
     */
    private function promptForFields(string $class): string
    {
        if (! $this->input->isInteractive()) {
            return '';
        }

        $types = $this->fieldTypeCatalog();

        $this->info("No --fields given — building {$class} interactively (leave the name blank to finish).");

        $tokens = [];
        while (true) {
            $name = $this->ask('Field name' . ($tokens === [] ? '' : ' (blank to finish)'));
            $name = is_string($name) ? trim($name) : '';
            if ($name === '') {
                break;
            }

            $type = $this->choice("Type for \"{$name}\"", $types, 'string');
            $type = is_string($type) ? $type : 'string';

            // belongsTo keys off a *_id column — nudge the name to the convention.
            if ($type === 'foreign' && ! str_ends_with($name, '_id')) {
                $name .= '_id';
                $this->line("  <comment>↳ foreign key →</comment> <info>{$name}</info>");
            }

            $spec = $type;
            if ($type === 'enum') {
                $opts = $this->ask('Choices (separate with |), e.g. draft|published');
                $opts = is_string($opts) ? str_replace([', ', ',', ' '], '|', trim($opts)) : '';
                $spec = 'enum:' . trim($opts, '|');
            }

            // Modifiers, only where they make sense (slug/m2m are already implicitly handled; a
            // boolean defaults to false so nullable is meaningless).
            if (! in_array($type, ['slug', 'boolean', 'belongsToMany'], true) && $this->confirm('Nullable (optional)?', false)) {
                $spec .= '?';
            }
            if (in_array($type, ['string', 'integer', 'email'], true) && $this->confirm('Unique?', false)) {
                $spec .= '^';
            }

            $token = "{$name}:{$spec}";
            $tokens[] = $token;
            $this->line("  <info>✓ added</info> {$token}");
        }

        return implode(', ', $tokens);
    }

    /**
     * The field types the `--fields` DSL accepts — DSL token => human description. Single source of
     * truth shared by the interactive builder's menu and `--list-fields`.
     *
     * @return array<string, string>
     */
    private function fieldTypeCatalog(): array
    {
        return [
            'string' => 'short text (VARCHAR)',
            'text' => 'long text',
            'richtext' => 'rich text / HTML — CKEditor WYSIWYG editor',
            'integer' => 'whole number',
            'decimal' => 'fixed-precision number — decimal:precision|scale (price:decimal:12|4)',
            'money' => 'exact money — stored as minor units (cents), shown with the currency symbol; price:money or price:money:KHR',
            'computed' => 'derived, read-only value (not stored) — total:computed:qty*price, or total:computed for a hand-written accessor',
            'rollup' => 'document total = sum of a child relation (money-aware) — total:rollup:lines.line_total',
            'boolean' => 'true / false toggle',
            'date' => 'date',
            'datetime' => 'date + time',
            'time' => 'time of day',
            'email' => 'email address',
            'url' => 'web address',
            'enum' => 'fixed set of choices — enum:draft|published',
            'slug' => 'unique URL key (auto from name)',
            'json' => 'structured data (JSON ↔ array)',
            'translatable' => 'per-locale text (JSON) — multi-language input + auto-translate',
            'image' => 'uploaded image (stored path)',
            'file' => 'uploaded file (stored path)',
            'media' => 'one library file (HasMedia) — pick from the media library or upload',
            'gallery' => 'many library files (HasMedia) — browse / upload / reorder',
            'foreign' => 'belongsTo another table (a *_id column). Self-ref/tree or odd name: foreign:table (parent_id:foreign:categories)',
            'belongsToMany' => 'many-to-many relation (aliases: m2m, manyToMany)',
        ];
    }

    /** Print the `--fields` DSL catalog (types, modifiers, an example) — the `--list-fields` reference. */
    private function showFieldTypes(): void
    {
        $this->info('admin-core:make --fields — supported types');
        $this->table(
            ['Type', 'Description'],
            collect($this->fieldTypeCatalog())->map(fn ($desc, $type) => [$type, $desc])->values()->all(),
        );

        $this->info('Modifiers (append to a type)');
        $this->table(
            ['Modifier', 'Meaning'],
            [
                ['?', 'nullable / optional             (price:decimal?)'],
                ['^', 'unique                          (slug:string^)'],
                ['#', 'plain database index            (status:string#)'],
                ['~', 'write-once: set on create, locked on edit  (sku:string^~)'],
                ['@', 'system: set by trusted code only, never in the form  (owner_id:integer@)'],
            ],
        );

        $this->line(' Special types: <comment>password</comment> (hashed), <comment>auth</comment> (current user id), <comment>sku</comment> (auto code), <comment>sequence</comment> (auto doc number, e.g. invoice_no:sequence:INV) — see the README.');
        $this->line(' Example: <comment>--fields="name:string^, price:decimal?, status:enum:draft|published, category_id:foreign"</comment>');
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

    private function createPermissions(string $kebab, string $plural, string $guard = 'web', bool $readOnly = false): void
    {
        if (! config('admin-core.permission.enabled') || ! Schema::hasTable('permissions')) {
            return;
        }

        $model = config('admin-core.permission.model', \Spatie\Permission\Models\Permission::class);
        // A read-only resource only needs the list permission — it's the gate for index/getData/show/export, and
        // the absent create/edit/delete permissions are exactly what hides the write buttons in the views.
        $actions = $readOnly ? ['list'] : ['list', 'create', 'edit', 'delete'];
        $names = array_map(fn ($action) => "{$action}-{$kebab}", $actions);

        foreach ($names as $name) {
            $model::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
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
            DB::table('permissions')->whereIn('name', $names)->where('guard_name', $guard)->update(['group_id' => $groupId]);
            $grouped = " under '{$groupName}'";
        }

        // Grant the new permissions to the super role so the resource works right
        // away — no need to re-run AccessSeeder after every admin-core:make. The role must
        // be on the SAME guard as the permissions, or Spatie throws GuardDoesNotMatch — so a
        // non-default guard can name its own super role via permission.guards.<guard>.super_role.
        $granted = '';
        $roleName = config("admin-core.permission.guards.{$guard}.super_role")
            ?? config('admin-core.permission.super_role', 'admin');
        if ($roleName && Schema::hasTable('roles')) {
            $roleModel = config('admin-core.permission.role_model', \Spatie\Permission\Models\Role::class);
            $role = $roleModel::where('name', $roleName)->where('guard_name', $guard)->first();
            if ($role) {
                $role->givePermissionTo($names);
                $granted = " (granted to '{$roleName}')";
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->line("  <info>permissions</info> " . implode('/', $actions) . "-{$kebab}{$grouped}{$granted}");
    }

    private function relative(string $path): string
    {
        return Str::after($path, base_path() . DIRECTORY_SEPARATOR);
    }
}
