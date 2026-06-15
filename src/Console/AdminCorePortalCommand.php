<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Scaffold a second admin portal (merchant, vendor, reseller, …) on its own auth guard:
 * a user model + migration, a login + dashboard, a route group, and the menu/permission
 * config — so `admin-core:make Order --portal=<name>` generates straight into it.
 *
 *   php artisan admin-core:portal merchant
 */
class AdminCorePortalCommand extends Command
{
    protected $signature = 'admin-core:portal {name : The portal name, e.g. merchant} {--force : Overwrite existing files}';

    protected $description = 'Scaffold a separate-guard admin portal (model + login + dashboard + routes + menu/permission config).';

    public function handle(): int
    {
        $name = Str::kebab(Str::singular($this->argument('name')));   // merchant
        $studly = Str::studly($name);                                 // Merchant
        $table = Str::snake(Str::pluralStudly($studly));              // merchants
        $force = (bool) $this->option('force');

        $tokens = ['__Portal__' => $studly, '__portal__' => $name, '__portals__' => $table];

        $files = [
            'model.stub' => app_path("Models/{$studly}.php"),
            'LoginController.stub' => app_path("Http/Controllers/{$studly}/Auth/LoginController.php"),
            'DashboardController.stub' => app_path("Http/Controllers/{$studly}/DashboardController.php"),
            'login.blade.stub' => resource_path("views/{$name}/auth/login.blade.php"),
            'layout.blade.stub' => resource_path("views/{$name}/layout.blade.php"),
            'dashboard.blade.stub' => resource_path("views/{$name}/dashboard.blade.php"),
            'factory.stub' => database_path("factories/{$studly}Factory.php"),
            'seeder.stub' => database_path("seeders/{$studly}Seeder.php"),
        ];
        // One create migration only (re-running never duplicates it).
        if (! glob(base_path("database/migrations/*_create_{$table}_table.php"))) {
            $files['migration.stub'] = base_path('database/migrations/' . date('Y_m_d_His') . "_create_{$table}_table.php");
        }

        foreach ($files as $stub => $target) {
            if (File::exists($target) && ! $force) {
                $this->warn('  <comment>exists</comment>  ' . $this->relative($target));
                continue;
            }
            File::ensureDirectoryExists(dirname($target));
            File::put($target, strtr(File::get($this->stub($stub)), $tokens));
            $this->line('  <info>created</info> ' . $this->relative($target));
        }

        File::ensureDirectoryExists(base_path("routes/{$studly}/Modules"));

        $this->addAuthGuard($name, $studly, $table);
        $this->registerPortalConfig($name);
        $this->wirePortalRoutes($name, $studly);

        $this->nextSteps($name, $studly, $table);

        return self::SUCCESS;
    }

    /** Add the guard + eloquent provider to config/auth.php (idempotent). */
    private function addAuthGuard(string $name, string $studly, string $table): void
    {
        $path = config_path('auth.php');
        if (! File::exists($path)) {
            $this->warn('  config/auth.php not found — add the guard/provider manually.');

            return;
        }

        $contents = File::get($path);
        if (str_contains($contents, "'{$name}' =>") && str_contains($contents, "'{$table}' =>")) {
            $this->line("  <comment>exists</comment>  '{$name}' guard in config/auth.php");

            return;
        }

        $guardLine = "        '{$name}' => ['driver' => 'session', 'provider' => '{$table}'],\n";
        $providerLine = "        '{$table}' => ['driver' => 'eloquent', 'model' => App\\Models\\{$studly}::class],\n";

        $patched = preg_replace('/(\x27guards\x27 => \[\n)/', '$1' . $guardLine, $contents, 1);
        $patched = preg_replace('/(\x27providers\x27 => \[\n)/', '$1' . $providerLine, (string) $patched, 1);

        if ($patched === null || $patched === $contents) {
            $this->warn("  couldn't edit config/auth.php — add the '{$name}' guard + '{$table}' provider by hand.");

            return;
        }

        File::put($path, $patched);
        $this->line("  <info>updated</info> config/auth.php ('{$name}' guard + '{$table}' provider)");
    }

    /** Add the portal's empty named menu (with marker) + per-guard super role to config/admin-core.php. */
    private function registerPortalConfig(string $name): void
    {
        $path = config_path('admin-core.php');
        if (! File::exists($path)) {
            $this->warn('  config/admin-core.php not published — publish it (vendor:publish --tag=admin-core-config) to wire the menu/permission for this portal.');

            return;
        }

        $contents = $original = File::get($path);

        if (! str_contains($contents, "// admin-core:menu:{$name}") && preg_match('/(\x27menus\x27 => \[\n)/', $contents)) {
            $block = "        '{$name}' => [\n"
                . "            ['label' => 'Dashboard', 'route' => '{$name}.dashboard', 'icon' => 'bi bi-speedometer2', 'match' => '{$name}'],\n"
                . "            // admin-core:menu:{$name}\n"
                . "        ],\n";
            $contents = preg_replace('/(\x27menus\x27 => \[\n)/', '$1' . $block, $contents, 1);
        }

        // Give the portal a super role on its own guard (Spatie requires same-guard).
        if (! str_contains($contents, "'{$name}' => [") && preg_match('/(\x27guards\x27 => \[)\]/', $contents)) {
            $contents = preg_replace('/(\x27guards\x27 => \[)\]/', "$1['{$name}' => ['super_role' => '{$name}-admin']]", $contents, 1);
        }

        if ($contents === $original) {
            $this->warn("  config/admin-core.php has no 'menus'/permission 'guards' anchors — publish the current config (vendor:publish --tag=admin-core-config) or add the '{$name}' menu + super-role by hand.");

            return;
        }

        File::put($path, $contents);
        $this->line('  <info>updated</info> config/admin-core.php (menu + permission for the portal — run config:clear if you cache config)');
    }

    /** Append the portal's route group to routes/web.php (idempotent, marker-wrapped). */
    private function wirePortalRoutes(string $name, string $studly): void
    {
        $web = base_path('routes/web.php');
        if (! File::exists($web)) {
            $this->warn('  routes/web.php not found — register the portal route group manually.');

            return;
        }

        if (str_contains(File::get($web), "admin-core:portal:{$name}")) {
            $this->line("  <comment>exists</comment>  '{$name}' route group in routes/web.php");

            return;
        }

        $login = "\\App\\Http\\Controllers\\{$studly}\\Auth\\LoginController::class";
        $dashboard = "\\App\\Http\\Controllers\\{$studly}\\DashboardController::class";

        $block = <<<PHP

// >>> admin-core:portal:{$name} (managed by admin-core:portal — do not edit the markers)
Route::middleware('web')->prefix('{$name}')->name('{$name}.')->group(function () {
    Route::middleware('guest:{$name}')->group(function () {
        Route::get('login', [{$login}, 'showLoginForm'])->name('login');
        Route::post('login', [{$login}, 'login']);
    });
    Route::middleware('auth:{$name}')->group(function () {
        Route::post('logout', [{$login}, 'logout'])->name('logout');
        Route::get('/', [{$dashboard}, 'index'])->name('dashboard');

        foreach (glob(base_path('routes/{$studly}/Modules/*.php')) ?: [] as \$module) {
            require \$module;
        }
    });
});
// <<< admin-core:portal:{$name}

PHP;

        File::append($web, $block);
        $this->line("  <info>updated</info> routes/web.php (added the '{$name}' portal route group)");
    }

    private function nextSteps(string $name, string $studly, string $table): void
    {
        $this->newLine();
        $this->info("Portal '{$name}' scaffolded.");
        $this->line('  Next:');
        $this->line("  1. <info>php artisan migrate</info>   (creates the {$table} table)");
        $this->line("  2. <info>php artisan db:seed --class={$studly}Seeder</info>   (a {$name}@example.com / password account + the '{$name}-admin' super role)");
        $this->line("  3. Generate resources into it: <info>php artisan admin-core:make Order --portal={$name}</info> then re-run the seeder to grant the new permissions.");
        $this->line("  4. Sign in at <info>/{$name}/login</info>.");
    }

    private function stub(string $name): string
    {
        $published = base_path("stubs/admin-core/portal/{$name}");

        return File::exists($published) ? $published : __DIR__ . "/../../stubs/portal/{$name}";
    }

    private function relative(string $path): string
    {
        return Str::after($path, base_path() . DIRECTORY_SEPARATOR);
    }
}
