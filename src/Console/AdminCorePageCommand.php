<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Scaffold a standalone (non-CRUD) admin page — a Reports screen, a Settings page,
 * a custom dashboard — the piece admin-core:make (CRUD) doesn't cover. Generates a
 * thin invokable controller, a Blade view (layout + page-header + card + empty-state
 * placeholder), and a route under routes/Web/Backend/Modules (auto-loaded inside the
 * `admin` group → admin.<slug>). By default it also adds a sidebar menu entry and a
 * `view-<slug>` permission granted to the super role; skip those with --no-menu /
 * --no-permission.
 *
 *   php artisan admin-core:page Reports
 */
class AdminCorePageCommand extends Command
{
    protected $signature = 'admin-core:page
                            {name : The page name, e.g. Reports or "Sales Report"}
                            {--no-permission : Skip creating + gating a view-<page> permission}
                            {--no-menu : Skip adding the sidebar menu entry}
                            {--force : Overwrite files that already exist}';

    protected $description = 'Scaffold a standalone (non-CRUD) admin page: invokable controller + view + route (+ sidebar menu + permission).';

    public function handle(): int
    {
        $class = Str::studly((string) $this->argument('name'));
        if ($class === '') {
            $this->error('Provide a page name, e.g. php artisan admin-core:page Reports');

            return self::FAILURE;
        }

        $controller = "{$class}Controller";
        $slug = Str::kebab($class);                 // Reports → reports, SalesReport → sales-report
        $label = Str::headline($class);             // Reports / Sales Report
        $view = "backend.pages.{$slug}";
        $routeNs = config('admin-core.route.name_prefix', 'admin.');
        $route = "{$routeNs}{$slug}";
        $guard = config('admin-core.permission.guard', config('auth.defaults.guard', 'web'));
        $withPermission = ! $this->option('no-permission');
        $permission = "view-{$slug}";

        $targets = [
            'controller' => app_path("Http/Controllers/Backend/{$controller}.php"),
            'view' => resource_path("views/backend/pages/{$slug}.blade.php"),
            'route' => base_path("routes/Web/Backend/Modules/{$slug}.php"),
        ];

        foreach ($targets as $path) {
            if (File::exists($path) && ! $this->option('force')) {
                $this->error("Already exists: " . $this->relative($path) . ' (use --force to overwrite).');

                return self::FAILURE;
            }
        }

        // Controller + view from stubs, route inline (tiny).
        $this->put($targets['controller'], strtr(File::get($this->stub('page-controller.stub')), [
            '__AC_CONTROLLER__' => $controller,
            '__AC_VIEW__' => $view,
        ]));
        $this->put($targets['view'], strtr(File::get($this->stub('page-view.stub')), [
            '__AC_TITLE__' => $label,
        ]));

        $gate = $withPermission
            ? "->name('{$slug}')\n    ->middleware(config('admin-core.permission.enabled') ? 'permission:{$permission}' : [])"
            : "->name('{$slug}')";
        $this->put($targets['route'], <<<PHP
        <?php

        use App\\Http\\Controllers\\Backend\\{$controller};
        use Illuminate\\Support\\Facades\\Route;

        Route::get('{$slug}', {$controller}::class)
            {$gate};

        PHP);

        if ($withPermission) {
            $this->createPermission($permission, $label, $guard);
        }
        if (! $this->option('no-menu')) {
            $this->registerMenuItem($label, $slug, $route, $withPermission ? $permission : null);
        }

        $this->newLine();
        $this->info("Page '{$class}' scaffolded.");
        $this->line("  Page: /" . trim(rtrim($routeNs, '.'), '/') . "/{$slug} (name: {$route})");
        $this->line("  Add your content in <info>" . $this->relative($targets['view']) . "</info>.");

        return self::SUCCESS;
    }

    private function createPermission(string $permission, string $label, string $guard): void
    {
        if (! config('admin-core.permission.enabled') || ! Schema::hasTable('permissions')) {
            return;
        }

        $model = config('admin-core.permission.model', \Spatie\Permission\Models\Permission::class);
        $model::firstOrCreate(['name' => $permission, 'guard_name' => $guard]);

        // File it under a "Pages" group so it shows organised in the role-edit tree.
        if (Schema::hasTable('group_permissions') && Schema::hasColumn('permissions', 'group_id')) {
            $groupId = DB::table('group_permissions')->where('name', 'Pages')->value('id');
            if (! $groupId) {
                $parentId = DB::table('group_permissions')->where('name', 'All')->value('id');
                $row = [
                    'name' => 'Pages',
                    'parent_id' => $parentId,
                    'sort' => (int) DB::table('group_permissions')->where('parent_id', $parentId)->max('sort') + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('group_permissions', 'uuid')) {
                    $row['uuid'] = (string) Str::uuid7();
                }
                $groupId = DB::table('group_permissions')->insertGetId($row);
            }
            DB::table('permissions')->where('name', $permission)->where('guard_name', $guard)->update(['group_id' => $groupId]);
        }

        $granted = '';
        $roleName = config("admin-core.permission.guards.{$guard}.super_role")
            ?? config('admin-core.permission.super_role', 'admin');
        if ($roleName && Schema::hasTable('roles')) {
            $roleModel = config('admin-core.permission.role_model', \Spatie\Permission\Models\Role::class);
            $role = $roleModel::where('name', $roleName)->where('guard_name', $guard)->first();
            if ($role) {
                $role->givePermissionTo($permission);
                $granted = " (granted to '{$roleName}')";
            }
        }

        $this->line("  <info>permission</info> {$permission}{$granted}");
    }

    /**
     * Add the page to the data-driven sidebar (the `// admin-core:menu` marker in
     * config/admin-core.php, rendered + permission-filtered by the sidebar-menu
     * component). Idempotent; warns if the marker is missing.
     */
    private function registerMenuItem(string $label, string $slug, string $route, ?string $can): void
    {
        $config = config_path('admin-core.php');
        $markerRe = '/\/\/ admin-core:menu(?![:\w])/';

        if (! File::exists($config) || ! preg_match($markerRe, File::get($config))) {
            $this->warn('  menu: add a sidebar entry by hand — no `// admin-core:menu` marker in config/admin-core.php.');

            return;
        }

        $contents = File::get($config);
        if (str_contains($contents, "'{$route}'")) {
            return; // already present — idempotent
        }

        $canPart = $can ? "'can' => '{$can}', " : '';
        $urlPrefix = rtrim(config('admin-core.route.name_prefix', 'admin.'), '.');
        $entry = "['label' => '{$label}', 'route' => '{$route}', 'icon' => 'bi bi-file-earmark-text', {$canPart}'match' => '{$urlPrefix}/{$slug}*'],";
        $contents = preg_replace_callback($markerRe, fn () => $entry . "\n        // admin-core:menu", $contents, 1);
        File::put($config, $contents);
        $this->line('  <info>menu</info> added "' . $label . '" to config/admin-core.php (run config:clear if you cache config)');
    }

    private function put(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
        $this->line('  <info>created</info> ' . $this->relative($path));
    }

    /** Published stubs (base_path/stubs/admin-core) win over the package's own. */
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
