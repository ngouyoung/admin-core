<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AdminCoreInstallCommand extends Command
{
    protected $signature = 'admin-core:install
                            {--access : Also scaffold auth (login) + Users/Roles/Permissions management}
                            {--force : Overwrite files that already exist}';

    protected $description = 'Scaffold the host-side glue admin-core needs: config, a starter backend layout, a dashboard, and the resource-route loader. Pass --access for a full login + access-management module.';

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
        $this->registerPermissionAlias();

        if ($this->option('access')) {
            $this->newLine();
            $this->info('Installing access module (auth + users/roles/permissions)…');
            $this->newLine();
            $this->installAccess();
        }

        $this->newLine();
        $this->info('admin-core installed.');
        $this->nextSteps();

        return self::SUCCESS;
    }

    private function publishConfigs(): void
    {
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

        if (! File::exists($web)) {
            $this->warn('  routes/web.php not found — skipped route wiring.');
            return;
        }

        if (str_contains(File::get($web), 'admin-core:routes')) {
            $this->line('  <comment>exists</comment>  admin-core route group already in routes/web.php');
            return;
        }

        $middleware = $this->option('access') ? "'middleware' => ['auth'], " : '';

        $block = <<<PHP

        /*
        |--------------------------------------------------------------------------
        | admin-core:routes — backend resource routes (php artisan admin-core:make)
        |--------------------------------------------------------------------------
        */
        Route::group([{$middleware}'prefix' => 'admin', 'as' => 'admin.'], function () {
            Route::view('/', 'backend.dashboard')->name('dashboard');

            foreach (glob(base_path('routes/Web/Backend/Modules/*.php')) ?: [] as \$module) {
                require \$module;
            }
        });

        PHP;

        File::append($web, $block);
        $this->line('  <info>updated</info> routes/web.php (added admin-core route group)');
    }

    /**
     * Register spatie's role/permission middleware aliases in bootstrap/app.php so
     * `permission:*` route middleware resolves. Idempotent via a marker.
     */
    private function registerPermissionAlias(): void
    {
        $app = base_path('bootstrap/app.php');

        if (! File::exists($app)) {
            $this->warn('  bootstrap/app.php not found — register the permission middleware alias manually.');
            return;
        }

        $contents = File::get($app);

        if (str_contains($contents, 'admin-core:middleware')) {
            $this->line('  <comment>exists</comment>  permission middleware alias in bootstrap/app.php');
            return;
        }

        $injection = <<<'PHP'
{
        // admin-core:middleware — spatie role/permission aliases
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
PHP;

        $patched = preg_replace(
            '/(->withMiddleware\(function \(Middleware \$middleware[^{]*)\{/',
            '$1' . $injection,
            $contents,
            1,
        );

        if ($patched === null || $patched === $contents) {
            $this->warn('  could not auto-edit bootstrap/app.php — add the permission middleware alias manually.');
            return;
        }

        File::put($app, $patched);
        $this->line('  <info>updated</info> bootstrap/app.php (registered permission middleware alias)');
    }

    // ------------------------------------------------------------------
    // Access module (--access)
    // ------------------------------------------------------------------

    private function installAccess(): void
    {
        $a = __DIR__ . '/../../stubs/access';

        // PHP classes (paths mirror their App\ namespace).
        $this->copyTree("$a/Models", app_path('Models'));
        $this->copyTree("$a/Auth", app_path('Http/Controllers/Auth'));
        $this->copyTree("$a/Http", app_path('Http'));
        $this->copyTree("$a/Services", app_path('Services'));
        $this->copyTree("$a/database/seeders", database_path('seeders'));

        // Views.
        $this->copyTree("$a/views/users", resource_path('views/backend/pages/users'));
        $this->copyTree("$a/views/roles", resource_path('views/backend/pages/roles'));
        $this->copyTree("$a/views/permissions", resource_path('views/backend/pages/permissions'));
        $this->copy("$a/views/auth/login.blade.php.stub", resource_path('views/auth/login.blade.php'));

        // Route module + auth routes.
        $this->copy("$a/routes/access.php.stub", base_path('routes/Web/Backend/Modules/access.php'));
        $this->appendAuthRoutes("$a/routes/auth.php.stub");

        // Patch the User model and the sidebar.
        $this->addHasRolesTrait();
        $this->addSidebarLinks();
    }

    private function appendAuthRoutes(string $stub): void
    {
        $web = base_path('routes/web.php');

        if (str_contains(File::get($web), 'admin-core:auth')) {
            $this->line('  <comment>exists</comment>  auth routes already in routes/web.php');
            return;
        }

        File::append($web, "\n" . File::get($stub));
        $this->line('  <info>updated</info> routes/web.php (added login/logout routes)');
    }

    private function addHasRolesTrait(): void
    {
        $model = app_path('Models/User.php');

        if (! File::exists($model)) {
            $this->warn('  app/Models/User.php not found — add `use Spatie\\Permission\\Traits\\HasRoles;` yourself.');
            return;
        }

        $contents = File::get($model);

        if (str_contains($contents, 'HasRoles')) {
            $this->line('  <comment>exists</comment>  HasRoles trait on User model');
            return;
        }

        // Add the import after the Notifiable import, and the trait to the `use` line in the class body.
        $contents = preg_replace(
            '/(use Illuminate\\\\Notifications\\\\Notifiable;)/',
            "$1\nuse Spatie\\Permission\\Traits\\HasRoles;",
            $contents,
            1,
        );
        $contents = preg_replace(
            '/(use HasFactory, Notifiable)(;)/',
            '$1, HasRoles$2',
            $contents,
            1,
        );

        File::put($model, $contents);
        $this->line('  <info>updated</info> app/Models/User.php (added HasRoles trait)');
    }

    private function addSidebarLinks(): void
    {
        $layout = resource_path('views/backend/layouts/app.blade.php');

        if (! File::exists($layout)) {
            return;
        }

        $contents = File::get($layout);

        if (str_contains($contents, "route('admin.users.index')")) {
            $this->line('  <comment>exists</comment>  access links already in sidebar');
            return;
        }

        $links = <<<'BLADE'
<li class="nav-item">
                <a href="{{ route('admin.users.index') }}" class="nav-link text-white">
                    <i class="fas fa-users me-2"></i> Users
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.roles.index') }}" class="nav-link text-white">
                    <i class="fas fa-user-shield me-2"></i> Roles
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.permissions.index') }}" class="nav-link text-white">
                    <i class="fas fa-key me-2"></i> Permissions
                </a>
            </li>
            {{-- admin-core:sidebar --}}
BLADE;

        // Inject before the placeholder comment in the published layout.
        $patched = str_replace('{{-- Add generated resources here', $links . "\n            {{-- Add generated resources here", $contents);

        if ($patched === $contents) {
            $this->warn('  could not auto-edit the sidebar — add nav links to backend/layouts/app.blade.php manually.');
            return;
        }

        File::put($layout, $patched);
        $this->line('  <info>updated</info> backend/layouts/app.blade.php (added access nav links)');
    }

    // ------------------------------------------------------------------

    private function copyTree(string $srcDir, string $destDir): void
    {
        foreach (File::allFiles($srcDir) as $file) {
            $relative = ltrim(str_replace($srcDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $target = $destDir . DIRECTORY_SEPARATOR . preg_replace('/\.stub$/', '', $relative);
            $this->copy($file->getPathname(), $target);
        }
    }

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
        $this->line('  1. Spatie tables:  <info>php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider" && php artisan migrate</info>');

        if ($this->option('access')) {
            $this->line('  2. Seed an admin:  <info>php artisan db:seed --class=Database\\Seeders\\AccessSeeder</info>');
            $this->line('  3. Log in at <info>/login</info> with <info>admin@example.com / password</info>, then manage users & roles.');
            $this->line('  4. Scaffold more: <info>php artisan admin-core:make Product --migration</info> (re-run the seeder to grant admin the new permissions).');
        } else {
            $this->line('  2. Scaffold a resource: <info>php artisan admin-core:make Product --migration && php artisan migrate</info>');
            $this->line('  3. Add it to the sidebar in <info>resources/views/backend/layouts/app.blade.php</info>.');
            $this->line('  4. For login + user/role management, re-run with <info>php artisan admin-core:install --access</info>.');
        }
    }
}
