<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AdminCoreInstallCommand extends Command
{
    protected $signature = 'admin-core:install {--force : Overwrite files that already exist}';

    protected $description = 'Scaffold the host-side glue admin-core needs: config, a starter backend layout, a dashboard, and the resource-route loader.';

    /** Source stubs live in the package; published files land in the host. */
    private string $stubs;

    public function handle(): int
    {
        $this->stubs = __DIR__ . '/../../stubs/install';

        $this->info('Installing admin-core…');
        $this->newLine();

        $this->publishConfigs();
        $this->publishViews();
        $this->ensureModulesDirectory();
        $this->wireRoutes();

        $this->newLine();
        $this->info('admin-core installed.');
        $this->nextSteps();

        return self::SUCCESS;
    }

    private function publishConfigs(): void
    {
        // admin-core.php ships with the package; reuse its publish source.
        $this->copy(__DIR__ . '/../../config/admin-core.php', config_path('admin-core.php'));
        $this->copyStub('class.php.stub', config_path('class.php'));
    }

    private function publishViews(): void
    {
        $this->copyStub('layout.blade.php.stub', resource_path('views/backend/layouts/app.blade.php'));
        $this->copyStub('dashboard.blade.php.stub', resource_path('views/backend/dashboard.blade.php'));
    }

    private function ensureModulesDirectory(): void
    {
        $dir = base_path('routes/Web/Backend/Modules');
        if (! File::isDirectory($dir)) {
            File::ensureDirectoryExists($dir);
            File::put($dir . '/.gitkeep', '');
            $this->line('  <info>created</info> routes/Web/Backend/Modules/');
        }
    }

    /**
     * Append an `admin` route group (with the dashboard route + the generated
     * resource-route loader) to routes/web.php — once. Idempotent via a marker.
     */
    private function wireRoutes(): void
    {
        $web = base_path('routes/web.php');
        $marker = 'admin-core:routes';

        if (! File::exists($web)) {
            $this->warn('  routes/web.php not found — skipped route wiring.');
            return;
        }

        if (str_contains(File::get($web), $marker)) {
            $this->line('  <comment>exists</comment>  admin-core route group already in routes/web.php');
            return;
        }

        $block = <<<PHP

        /*
        |--------------------------------------------------------------------------
        | admin-core:routes — backend resource routes (php artisan admin-core:make)
        |--------------------------------------------------------------------------
        | Wrap this group in your auth/permission middleware before going live, e.g.
        | ->middleware(['auth', 'permission:access']).
        */
        Route::group(['prefix' => 'admin', 'as' => 'admin.'], function () {
            Route::view('/', 'backend.dashboard')->name('dashboard');

            foreach (glob(base_path('routes/Web/Backend/Modules/*.php')) ?: [] as \$module) {
                require \$module;
            }
        });

        PHP;

        File::append($web, $block);
        $this->line('  <info>updated</info> routes/web.php (added admin-core route group)');
    }

    // ---------------------------------------------------------------------

    private function copyStub(string $stub, string $target): void
    {
        $this->copy("{$this->stubs}/{$stub}", $target);
    }

    private function copy(string $source, string $target): void
    {
        $rel = ltrim(str_replace(base_path(), '', $target), DIRECTORY_SEPARATOR);

        if (File::exists($target) && ! $this->option('force')) {
            $this->line("  <comment>exists</comment>  {$rel} (use --force to overwrite)");
            return;
        }

        File::ensureDirectoryExists(dirname($target));
        File::copy($source, $target);
        $this->line("  <info>published</info> {$rel}");
    }

    private function nextSteps(): void
    {
        $this->newLine();
        $this->line('<options=bold>Next steps:</>');
        $this->line('  1. Publish & run spatie migrations:  <info>php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider" && php artisan migrate</info>');
        $this->line('  2. Scaffold a resource:              <info>php artisan admin-core:make Product --migration</info>');
        $this->line('  3. Add it to the sidebar in          <info>resources/views/backend/layouts/app.blade.php</info>');
        $this->line('  4. Gate the routes:                  wrap the <info>admin-core:routes</info> group in auth/permission middleware,');
        $this->line('     or set <info>permission.enabled => false</info> in <info>config/admin-core.php</info> to browse without auth first.');
    }
}
