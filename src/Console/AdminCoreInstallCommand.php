<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AdminCoreInstallCommand extends Command
{
    protected $signature = 'admin-core:install
                            {--access : Also scaffold the full AdminLTE 4 (Vite) front-end + auth + Users/Roles/Permissions/Group-Permissions}
                            {--force : Overwrite files that already exist}';

    protected $description = 'Scaffold the host-side glue admin-core needs. Pass --access for the full AdminLTE 4 front-end + login + access-management module.';

    private string $stubs;

    public function handle(): int
    {
        $this->stubs = __DIR__ . '/../../stubs/install';
        $access = $this->option('access');

        $this->info('Installing admin-core…');
        $this->newLine();

        $this->publishConfigs();
        $this->copyStub('activity_logs_table.php.stub', database_path('migrations/0001_01_01_000020_create_activity_logs_table.php'));
        $this->ensureModulesDirectory();
        $this->wireRoutes();
        $this->registerPermissionAlias();

        if ($access) {
            $this->copyStub('dashboard.blade.php.stub', resource_path('views/backend/dashboard.blade.php'));
            $this->newLine();
            $this->info('Installing AdminLTE 4 front-end + access module…');
            $this->newLine();
            $this->installFrontend();
            $this->installAccess();
        } else {
            // Minimal, zero-build starter layout.
            $this->copyStub('layout.blade.php.stub', resource_path('views/backend/layouts/app.blade.php'));
            $this->copyStub('dashboard.blade.php.stub', resource_path('views/backend/dashboard.blade.php'));
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

    private function ensureModulesDirectory(): void
    {
        $dir = base_path('routes/Web/Backend/Modules');
        if (! File::isDirectory($dir)) {
            File::ensureDirectoryExists($dir);
            File::put($dir . '/.gitkeep', '');
            $this->line('  <info>created</info> routes/Web/Backend/Modules/');
        }
    }

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

// >>> admin-core:routes (managed by admin-core:install — do not edit the markers)
Route::group([{$middleware}'prefix' => 'admin', 'as' => 'admin.'], function () {
    Route::view('/', 'backend.dashboard')->name('dashboard');

    foreach (glob(base_path('routes/Web/Backend/Modules/*.php')) ?: [] as \$module) {
        require \$module;
    }
});
// <<< admin-core:routes

PHP;

        File::append($web, $block);
        $this->line('  <info>updated</info> routes/web.php (added admin-core route group)');
    }

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
        // >>> admin-core:middleware
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
        // <<< admin-core:middleware
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
    // Front-end kit (AdminLTE 4 / Vite)
    // ------------------------------------------------------------------

    private function installFrontend(): void
    {
        $fe = __DIR__ . '/../../stubs/frontend';

        // JS / SCSS sources. admin-core's app.js overwrites the framework default;
        // the host's own vite.config builds it (and keeps Laravel's app.css/Tailwind
        // welcome page working), so we deliberately do NOT touch vite.config.js.
        $this->copyTree("$fe/resources", resource_path(), force: true);

        $this->mergePackageJson("$fe/package.json.stub");

        // AdminLTE layout + nav/sidebar/footer + login.
        $this->copyTree("$fe/views/backend", resource_path('views/backend'), force: true);
        $this->copy("$fe/views/auth/login.blade.php.stub", resource_path('views/auth/login.blade.php'), force: true);
    }

    private function mergePackageJson(string $stub): void
    {
        $pkgPath = base_path('package.json');
        if (! File::exists($pkgPath)) {
            $this->copy($stub, $pkgPath, force: true);
            return;
        }

        $host = json_decode(File::get($pkgPath), true) ?: [];
        $add = json_decode(File::get($stub), true) ?: [];

        foreach (['dependencies', 'devDependencies'] as $section) {
            $host[$section] = array_merge($host[$section] ?? [], $add[$section] ?? []);
            ksort($host[$section]);
        }

        File::put($pkgPath, json_encode($host, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->line('  <info>updated</info> package.json (merged AdminLTE / DataTables / select2 deps)');
    }

    // ------------------------------------------------------------------
    // Access module (assessments: users, roles, permissions, group permissions)
    // ------------------------------------------------------------------

    private function installAccess(): void
    {
        $a = __DIR__ . '/../../stubs/access';

        $this->copyTree("$a/Models", app_path('Models'));
        $this->copyTree("$a/Auth", app_path('Http/Controllers/Auth'));
        $this->copyTree("$a/Http", app_path('Http'));
        $this->copyTree("$a/Services", app_path('Services'));
        $this->copyTree("$a/database/seeders", database_path('seeders'));
        $this->copyTree("$a/database/migrations", database_path('migrations'));
        $this->copyTree("$a/views/backend", resource_path('views/backend'));

        $this->copy("$a/routes/assessments.php.stub", base_path('routes/Web/Backend/Modules/assessments.php'));
        $this->copy("$a/routes/account.php.stub", base_path('routes/Web/Backend/Modules/account.php'));
        $this->appendAuthRoutes("$a/routes/auth.php.stub");
        $this->addHasRolesTrait();
        $this->publishSpatieConfig();
    }

    private function appendAuthRoutes(string $stub): void
    {
        $web = base_path('routes/web.php');
        if (str_contains(File::get($web), 'admin-core:auth')) {
            $this->line('  <comment>exists</comment>  auth routes already in routes/web.php');
            return;
        }
        $block = "\n// >>> admin-core:auth\n" . trim(File::get($stub)) . "\n// <<< admin-core:auth\n";
        File::append($web, $block);
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

        $contents = preg_replace(
            '/(use Illuminate\\\\Notifications\\\\Notifiable;)/',
            "$1\nuse Spatie\\Permission\\Traits\\HasRoles;",
            $contents,
            1,
        );
        $contents = preg_replace('/(use HasFactory, Notifiable)(;)/', '$1, HasRoles$2', $contents, 1);

        File::put($model, $contents);
        $this->line('  <info>updated</info> app/Models/User.php (added HasRoles trait)');
    }

    private function publishSpatieConfig(): void
    {
        // Only the config — admin-core ships its own permission migration (with group_id).
        if (! File::exists(config_path('permission.php'))) {
            $this->callSilently('vendor:publish', [
                '--provider' => 'Spatie\\Permission\\PermissionServiceProvider',
                '--tag' => 'permission-config',
            ]);
            $this->line('  <info>published</info> config/permission.php (spatie)');
        }
    }

    // ------------------------------------------------------------------

    private function copyTree(string $srcDir, string $destDir, bool $force = false): void
    {
        if (! File::isDirectory($srcDir)) {
            return;
        }
        foreach (File::allFiles($srcDir) as $file) {
            $relative = ltrim(str_replace($srcDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $target = $destDir . DIRECTORY_SEPARATOR . preg_replace('/\.stub$/', '', $relative);
            $this->copy($file->getPathname(), $target, $force);
        }
    }

    private function copyStub(string $stub, string $target): void
    {
        $this->copy("{$this->stubs}/{$stub}", $target);
    }

    private function copy(string $source, string $target, bool $force = false): void
    {
        $rel = ltrim(str_replace(base_path(), '', $target), DIRECTORY_SEPARATOR);

        if (File::exists($target) && ! $force && ! $this->option('force')) {
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

        if ($this->option('access')) {
            $this->line('  1. Build the front-end:  <info>npm install && npm run build</info>');
            $this->line('  2. Migrate:              <info>php artisan migrate</info>');
            $this->line('  3. Seed an admin:        <info>php artisan db:seed --class=Database\\Seeders\\AccessSeeder</info>');
            $this->line('  4. Log in at <info>/login</info> with <info>admin@example.com / password</info>.');
            $this->line('  5. Scaffold more:        <info>php artisan admin-core:make Product --migration</info> (re-run the seeder to grant admin the new permissions).');
        } else {
            $this->line('  1. Spatie tables:        <info>php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider" && php artisan migrate</info>');
            $this->line('  2. Scaffold a resource:  <info>php artisan admin-core:make Product --migration && php artisan migrate</info>');
            $this->line('  3. For the full AdminLTE UI + login + user/role management: <info>php artisan admin-core:install --access</info>');
        }
    }
}
