<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AdminCoreInstallCommand extends Command
{
    protected $signature = 'admin-core:install
                            {--access : Also scaffold the full Bootstrap 5 (Vite) admin theme + auth + Users/Roles/Permissions/Group-Permissions}
                            {--api-auth : Also scaffold Passport OAuth2 API auth (/api/login password grant, /api/logout, /api/me) for a decoupled front-end}
                            {--build : Run npm install && npm run build after publishing}
                            {--seed : Run migrate + seed the admin user after publishing}
                            {--force : Overwrite files that already exist}';

    protected $description = 'Scaffold the host-side glue admin-core needs. Pass --access for the full Bootstrap 5 admin theme + login + access-management module.';

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
            // Notifications table (skip if the app — or a previous run — already has one).
            if (! glob(database_path('migrations/*_create_notifications_table.php'))) {
                $this->copyStub('notifications_table.php.stub', database_path('migrations/0001_01_01_000021_create_notifications_table.php'));
            }
            $this->newLine();
            $this->info('Installing admin theme + access module…');
            $this->newLine();
            $this->installFrontend();
            $this->installAccess();
        } else {
            // Minimal, zero-build starter layout.
            $this->copyStub('layout.blade.php.stub', resource_path('views/backend/layouts/app.blade.php'));
            $this->copyStub('dashboard.blade.php.stub', resource_path('views/backend/dashboard.blade.php'));
        }

        if ($this->option('api-auth')) {
            $this->newLine();
            $this->info('Installing API auth (Passport)…');
            $this->newLine();
            $this->installApiAuth();
        }

        $this->newLine();
        $this->info('admin-core installed.');

        if ($access) {
            $this->buildAndSeed();
        }

        $this->nextSteps();

        return self::SUCCESS;
    }

    /** Offer (or, with --build/--seed, force) the remaining one-time setup. */
    private function buildAndSeed(): void
    {
        $interactive = $this->input->isInteractive();

        if ($this->option('build') || ($interactive && $this->confirm('Build the front-end now? (npm install && npm run build)', true))) {
            $this->runShell('npm install', 'Installing npm packages');
            $this->runShell('npm run build', 'Building front-end assets');
        }

        if ($this->option('seed') || ($interactive && $this->confirm('Migrate the database and create an admin user?', true))) {
            $this->call('migrate', ['--force' => true]);
            if (File::exists(database_path('seeders/AccessSeeder.php'))) {
                $this->call('db:seed', ['--class' => 'Database\\Seeders\\AccessSeeder', '--force' => true]);
            }
        }
    }

    private function runShell(string $command, string $label): void
    {
        $this->line("  <info>{$label}…</info>");
        $result = \Illuminate\Support\Facades\Process::path(base_path())->timeout(900)->run($command);

        $result->successful()
            ? $this->line("  <info>done</info> {$command}")
            : $this->warn("  '{$command}' failed — run it manually. " . trim($result->errorOutput()));
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

        if (str_contains($contents = File::get($web), 'admin-core:routes')) {
            $changed = false;

            // Already wired. If this is now an --access install but the admin group isn't behind
            // `auth` (e.g. a minimal install came first), add it — otherwise a guest reaches the
            // dashboard and the user-aware layout 500s instead of redirecting to login.
            if ($this->option('access') && str_contains($contents, "Route::group(['prefix' => 'admin'")) {
                $contents = str_replace(
                    "Route::group(['prefix' => 'admin'",
                    "Route::group(['middleware' => ['auth'], 'prefix' => 'admin'",
                    $contents,
                );
                $this->line('  <info>updated</info> routes/web.php (admin route group now requires auth)');
                $changed = true;
            }

            // Add the notification routes to an existing --access group (so re-running adopts the feature).
            if ($this->option('access') && ! str_contains($contents, 'Route::adminCoreNotifications')) {
                $contents = preg_replace(
                    "/(Route::view\('\/', 'backend\.dashboard'\)->name\('dashboard'\);)/",
                    "$1\n    Route::adminCoreNotifications();",
                    $contents,
                    1,
                );
                $this->line('  <info>updated</info> routes/web.php (added the notification routes)');
                $changed = true;
            }

            if ($changed) {
                File::put($web, $contents);
            } else {
                $this->line('  <comment>exists</comment>  admin-core route group already in routes/web.php');
            }

            return;
        }

        $middleware = $this->option('access') ? "'middleware' => ['auth'], " : '';
        // The notification routes need an authenticated user, so wire them only with --access.
        $notifications = $this->option('access') ? "\n    Route::adminCoreNotifications();" : '';

        $block = <<<PHP

// >>> admin-core:routes (managed by admin-core:install — do not edit the markers)
Route::group([{$middleware}'prefix' => 'admin', 'as' => 'admin.'], function () {
    Route::view('/', 'backend.dashboard')->name('dashboard');{$notifications}

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
    // Front-end kit (Bootstrap 5 / Vite)
    // ------------------------------------------------------------------

    private function installFrontend(): void
    {
        $fe = __DIR__ . '/../../stubs/frontend';

        // JS / SCSS sources. admin-core's app.js overwrites the framework default;
        // the host's own vite.config builds it (and keeps Laravel's app.css/Tailwind
        // welcome page working), so we deliberately do NOT replace vite.config.js —
        // we only inject the SCSS quietDeps so the Bootstrap build stays warning-free.
        $this->copyTree("$fe/resources", resource_path(), force: true);
        $this->ensureViteQuietDeps();

        $this->mergePackageJson("$fe/package.json.stub");

        // Layout + nav/sidebar/footer + login.
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
        $this->line('  <info>updated</info> package.json (merged theme / DataTables / select2 deps)');
    }

    /**
     * Inject a `css.preprocessorOptions.scss.quietDeps` block into the host's vite.config.js so
     * Bootstrap 5's legacy-Sass deprecation warnings don't flood `npm run build`. We don't replace
     * the file (it carries the host's own plugins/Tailwind); we only add the setting if it's absent.
     */
    private function ensureViteQuietDeps(): void
    {
        $vite = base_path('vite.config.js');
        if (! File::exists($vite)) {
            $this->warn('  vite.config.js not found — add a css.preprocessorOptions.scss { quietDeps: true } block to silence Bootstrap SCSS warnings.');

            return;
        }

        $contents = File::get($vite);
        if (str_contains($contents, 'quietDeps')) {
            $this->line('  <comment>exists</comment>  scss quietDeps already in vite.config.js');

            return;
        }

        $block = "    css: {\n"
            . "        preprocessorOptions: {\n"
            . "            // admin-core's Bootstrap SCSS uses legacy Sass APIs — silence those dep warnings.\n"
            . "            scss: { quietDeps: true, silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'] },\n"
            . "        },\n"
            . "    },\n";

        $patched = preg_replace('/(defineConfig\(\{\n)/', '$1' . $block, $contents, 1);

        if ($patched !== null && $patched !== $contents) {
            File::put($vite, $patched);
            $this->line('  <info>updated</info> vite.config.js (silence Bootstrap SCSS deprecation warnings)');
        } else {
            $this->warn('  couldn\'t edit vite.config.js — add a css.preprocessorOptions.scss { quietDeps: true } block by hand to silence Bootstrap SCSS warnings.');
        }
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
            "$1\nuse Ngos\\AdminCore\\Concerns\\HasPublicUuid;\nuse Spatie\\Permission\\Traits\\HasRoles;",
            $contents,
            1,
        );

        // Append to the in-class `use … Notifiable …;` trait line. Match it flexibly (indented use,
        // short names → no backslash) so a User with extra traits (Sanctum/Passport/Jetstream) or a
        // different order still works — the old exact `use HasFactory, Notifiable;` match silently
        // skipped those, leaving the traits imported but never applied.
        $contents = preg_replace(
            '/(\n[ \t]+use\s+[A-Za-z0-9_,\s]*\bNotifiable\b[A-Za-z0-9_,\s]*?)(;)/',
            '$1, HasRoles, HasPublicUuid$2',
            $contents,
            1,
            $applied,
        );

        File::put($model, $contents);

        $applied
            ? $this->line('  <info>updated</info> app/Models/User.php (added HasRoles trait)')
            : $this->warn('  added the imports to app/Models/User.php, but could not find the trait line — add `use HasRoles, HasPublicUuid;` inside the User class by hand.');
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
    // API auth (Passport OAuth2 password grant) — --api-auth
    // ------------------------------------------------------------------

    private function installApiAuth(): void
    {
        $a = __DIR__ . '/../../stubs/api-auth';

        $this->copy("$a/AuthController.php.stub", app_path('Http/Controllers/Api/AuthController.php'));
        $this->copy("$a/ApiAuthServiceProvider.php.stub", app_path('Providers/ApiAuthServiceProvider.php'));
        $this->registerProvider('App\\Providers\\ApiAuthServiceProvider');
        $this->wireApiRoutes("$a/auth-routes.php.stub");
        $this->ensureApiRouting();
        $this->switchApiGuard();

        // API modules directory for `admin-core:make … --api` route files.
        $dir = base_path('routes/Api/Modules');
        if (! File::isDirectory($dir)) {
            File::ensureDirectoryExists($dir);
            File::put($dir . '/.gitkeep', '');
            $this->line('  <info>created</info> routes/Api/Modules/');
        }

        // The scaffolding needs Passport — spell out the remaining one-time setup so /api/login works.
        $this->newLine();
        $this->warn('  Finish API auth setup (Laravel Passport):');
        if (! class_exists(\Laravel\Passport\Passport::class)) {
            $this->line('    composer require laravel/passport');
        }
        $this->line('    php artisan migrate                     # Passport tables');
        $this->line('    php artisan passport:keys               # OAuth keys (skip if already present)');
        $this->line('    php artisan passport:client --password  # create the password-grant client, then set');
        $this->line('    PASSPORT_PASSWORD_CLIENT_ID / PASSPORT_PASSWORD_CLIENT_SECRET in .env');
    }

    /** Add a provider class to bootstrap/providers.php (idempotent). */
    private function registerProvider(string $class): void
    {
        $file = base_path('bootstrap/providers.php');
        if (! File::exists($file)) {
            $this->warn("  bootstrap/providers.php not found — register {$class} manually.");
            return;
        }

        $contents = File::get($file);
        if (str_contains($contents, $class)) {
            $this->line('  <comment>exists</comment>  ApiAuthServiceProvider in bootstrap/providers.php');
            return;
        }

        $patched = preg_replace('/\];/', "    {$class}::class,\n];", $contents, 1);
        if ($patched === null || $patched === $contents) {
            $this->warn("  could not auto-edit bootstrap/providers.php — register {$class} manually.");
            return;
        }

        File::put($file, $patched);
        $this->line('  <info>updated</info> bootstrap/providers.php (registered ApiAuthServiceProvider)');
    }

    /** Create routes/api.php (or append to it) with the auth routes + the Api/Modules loader. */
    private function wireApiRoutes(string $fragmentStub): void
    {
        $api = base_path('routes/api.php');
        $authBlock = "\n// >>> admin-core:api-auth\n" . trim(File::get($fragmentStub)) . "\n// <<< admin-core:api-auth\n";
        $modulesBlock = <<<'PHP'

// >>> admin-core:api-modules
// API modules generated with `admin-core:make … --api`.
foreach (glob(__DIR__ . '/Api/Modules/*.php') ?: [] as $apiModule) {
    require $apiModule;
}
// <<< admin-core:api-modules

PHP;

        if (! File::exists($api)) {
            File::put($api, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n" . $authBlock . $modulesBlock);
            $this->line('  <info>created</info> routes/api.php (auth routes + API module loader)');
            return;
        }

        $contents = File::get($api);
        $appended = false;

        if (! str_contains($contents, 'admin-core:api-auth')) {
            File::append($api, $authBlock);
            $appended = true;
        }
        // Skip the loader when the host already requires Api/Modules (hand-wired).
        if (! str_contains(File::get($api), 'Api/Modules')) {
            File::append($api, $modulesBlock);
            $appended = true;
        }

        $this->line($appended
            ? '  <info>updated</info> routes/api.php (auth routes / API module loader)'
            : '  <comment>exists</comment>  api auth routes already in routes/api.php');
    }

    /** Make sure bootstrap/app.php actually loads routes/api.php. */
    private function ensureApiRouting(): void
    {
        $app = base_path('bootstrap/app.php');
        if (! File::exists($app)) {
            return;
        }

        $contents = File::get($app);
        if (str_contains($contents, 'routes/api.php')) {
            return; // already routed
        }

        $patched = preg_replace(
            "/(web:\s*__DIR__\s*\.\s*'\/\.\.\/routes\/web\.php',)/",
            "$1\n        api: __DIR__.'/../routes/api.php',",
            $contents,
            1,
        );

        if ($patched === null || $patched === $contents) {
            $this->warn("  could not auto-edit bootstrap/app.php — add `api: __DIR__.'/../routes/api.php'` to withRouting() manually.");
            return;
        }

        File::put($app, $patched);
        $this->line('  <info>updated</info> bootstrap/app.php (registered routes/api.php)');
    }

    /** Point the published admin-core api middleware at the Passport guard. */
    private function switchApiGuard(): void
    {
        $config = config_path('admin-core.php');
        if (! File::exists($config)) {
            return;
        }

        $contents = File::get($config);
        if (! str_contains($contents, "'auth:sanctum'")) {
            $this->line('  <comment>exists</comment>  admin-core api middleware already non-sanctum');
            return;
        }

        File::put($config, str_replace("'auth:sanctum'", "'auth:api'", $contents));
        $this->line("  <info>updated</info> config/admin-core.php (api middleware → 'auth:api')");
        $this->warn("  add an 'api' guard with driver 'passport' to config/auth.php — see the next steps below.");
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
            $this->line('  Log in at <info>/login</info> with <info>admin@example.com / password</info>.');
            $this->line('  If you skipped the prompts, run: <info>npm install && npm run build</info> then');
            $this->line('     <info>php artisan migrate && php artisan db:seed --class=Database\\Seeders\\AccessSeeder</info>');
            $this->line('  Scaffold resources: <info>php artisan admin-core:make Product --migration --fields="name:string"</info>');
        } else {
            $this->line('  1. Spatie tables:        <info>php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider" && php artisan migrate</info>');
            $this->line('  2. Scaffold a resource:  <info>php artisan admin-core:make Product --migration && php artisan migrate</info>');
            $this->line('  3. For the full admin theme + login + user/role management: <info>php artisan admin-core:install --access</info>');
        }

        if ($this->option('api-auth')) {
            $this->newLine();
            $this->line('<options=bold>API auth — finish the Passport setup (it cannot be installed by an artisan command):</>');
            $this->line('  1. <info>composer require laravel/passport</info>');
            $this->line('  2. <info>php artisan migrate</info>                          # oauth tables');
            $this->line('  3. <info>php artisan passport:keys</info>                    # this env\'s keys (git-ignored)');
            $this->line('  4. <info>php artisan passport:client --password --name="API" --provider=users</info>');
            $this->line('     → put the printed id/secret in .env as <info>PASSPORT_PASSWORD_CLIENT_ID</info> / <info>PASSPORT_PASSWORD_CLIENT_SECRET</info>');
            $this->line('  5. Add the <info>api</info> guard to <info>config/auth.php</info>: <comment>\'api\' => [\'driver\' => \'passport\', \'provider\' => \'users\']</comment>');
            $this->line('  6. Add <info>use Laravel\\Passport\\HasApiTokens;</info> (+ the trait) to <info>App\\Models\\User</info>');
            $this->line('  Then: <info>POST /api/login</info> {email, password} → access_token; <info>GET /api/me</info> with the Bearer token.');
        }
    }
}
