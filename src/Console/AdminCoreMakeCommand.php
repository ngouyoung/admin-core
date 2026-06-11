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

        $fields = (new FieldSet($this->option('fields')))
            ->setTable($snakePlural)
            ->setUuid($uuid)
            ->setSoftDeletes($soft)
            ->setAudit($audit)
            ->setSortable($sortable);

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
        ];

        $files = [
            'model.stub' => app_path("Models/{$class}.php"),
            'service.stub' => app_path("Services/{$plural}/{$class}Service.php"),
            'controller.stub' => app_path("Http/Controllers/Backend/{$class}Controller.php"),
            'store-request.stub' => app_path("Http/Requests/{$class}/Store{$class}Request.php"),
            'update-request.stub' => app_path("Http/Requests/{$class}/Update{$class}Request.php"),
            'routes.stub' => base_path("routes/Web/Backend/Modules/{$snakePlural}.php"),
            'views/index.stub' => resource_path("views/backend/pages/{$snakePlural}/index.blade.php"),
            'views/show.stub' => resource_path("views/backend/pages/{$snakePlural}/show.blade.php"),
            'views/create.stub' => resource_path("views/backend/pages/{$snakePlural}/create.blade.php"),
            'views/edit.stub' => resource_path("views/backend/pages/{$snakePlural}/edit.blade.php"),
            'views/form.stub' => resource_path("views/backend/pages/{$snakePlural}/partials/form.blade.php"),
            'views/thead.stub' => resource_path("views/backend/pages/{$snakePlural}/partials/thead.blade.php"),
            'views/scripts.stub' => resource_path("views/backend/pages/{$snakePlural}/partials/scripts.blade.php"),
            'factory.stub' => database_path("factories/{$class}Factory.php"),
            'seeder.stub' => database_path("seeders/{$class}Seeder.php"),
            'policy.stub' => app_path("Policies/{$class}Policy.php"),
        ];

        if ($soft) {
            $files['views/trash.stub'] = resource_path("views/backend/pages/{$snakePlural}/trash.blade.php");
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

        $this->createPermissions($kebab, $plural);
        $this->registerSidebarLink($plural, $snakePlural);

        $this->newLine();
        $this->info("Resource '{$class}' scaffolded.");
        $this->line("  Route:  /admin/{$snakePlural}   (name: admin.{$snakePlural}.*)");
        $this->line("  Run <info>php artisan migrate</info>, then visit /admin/{$snakePlural} — permissions are already granted to the admin role.");

        return self::SUCCESS;
    }

    /**
     * Inject a nav link at the `{{-- admin-core:menu --}}` marker in the sidebar
     * partial (or the minimal layout). Idempotent; silently skips if no marker.
     */
    private function registerSidebarLink(string $plural, string $snakePlural): void
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

        $label = \Illuminate\Support\Str::headline($plural);
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
