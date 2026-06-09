<?php

use Illuminate\Support\Facades\File;

/*
 * Exercises `admin-core:install` (minimal, no --access) against the Testbench
 * skeleton: publishes config/migration/views and string-edits routes/web.php +
 * bootstrap/app.php. Those two host files are replaced with controlled fixtures
 * before the run and restored afterwards; every published file is deleted after.
 */

/** Host files install mutates (snapshotted + restored). */
function installHostFiles(): array
{
    return [base_path('routes/web.php'), base_path('bootstrap/app.php'), base_path('package.json')];
}

/** Files/dirs install publishes (deleted after each test). */
function installPublished(): array
{
    return [
        config_path('admin-core.php'),
        config_path('class.php'),
        resource_path('views/backend'),
        base_path('routes/Web'),
        database_path('migrations/0001_01_01_000020_create_activity_logs_table.php'),
    ];
}

beforeEach(function () {
    // Snapshot the host files we'll mutate (null = it did not exist).
    $this->snapshot = [];
    foreach (installHostFiles() as $path) {
        $this->snapshot[$path] = File::exists($path) ? File::get($path) : null;
    }

    // Controlled fixtures so the route/append + middleware regex have a known target.
    File::ensureDirectoryExists(base_path('routes'));
    File::put(base_path('routes/web.php'), "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");

    File::ensureDirectoryExists(base_path('bootstrap'));
    File::put(base_path('bootstrap/app.php'), <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->create();
PHP);
});

afterEach(function () {
    foreach (installPublished() as $path) {
        File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);
    }
    foreach ($this->snapshot as $path => $contents) {
        $contents === null ? File::delete($path) : File::put($path, $contents);
    }
});

it('publishes config, the activity-log migration and the minimal layout', function () {
    $this->artisan('admin-core:install')->assertSuccessful();

    expect(File::exists(config_path('admin-core.php')))->toBeTrue()
        ->and(File::exists(config_path('class.php')))->toBeTrue()
        ->and(File::exists(database_path('migrations/0001_01_01_000020_create_activity_logs_table.php')))->toBeTrue()
        ->and(File::exists(resource_path('views/backend/layouts/app.blade.php')))->toBeTrue()
        ->and(File::exists(resource_path('views/backend/dashboard.blade.php')))->toBeTrue()
        ->and(File::isDirectory(base_path('routes/Web/Backend/Modules')))->toBeTrue();
});

it('wires the admin route group into routes/web.php', function () {
    $this->artisan('admin-core:install')->assertSuccessful();

    expect(File::get(base_path('routes/web.php')))
        ->toContain('admin-core:routes')
        ->toContain("'prefix' => 'admin'")
        ->toContain("'as' => 'admin.'");
});

it('registers the permission middleware alias in bootstrap/app.php', function () {
    $this->artisan('admin-core:install')->assertSuccessful();

    expect(File::get(base_path('bootstrap/app.php')))
        ->toContain('admin-core:middleware')
        ->toContain('PermissionMiddleware')
        ->toContain("'permission' =>");
});

it('is idempotent — re-running does not double-wire', function () {
    $this->artisan('admin-core:install')->assertSuccessful();
    $this->artisan('admin-core:install')->assertSuccessful();

    expect(substr_count(File::get(base_path('routes/web.php')), '>>> admin-core:routes'))->toBe(1)
        ->and(substr_count(File::get(base_path('bootstrap/app.php')), '>>> admin-core:middleware'))->toBe(1);
});
