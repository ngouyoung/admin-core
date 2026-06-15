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
    return [
        base_path('routes/web.php'),
        base_path('routes/api.php'),
        base_path('bootstrap/app.php'),
        base_path('bootstrap/providers.php'),
        base_path('package.json'),
    ];
}

/** Files/dirs install publishes (deleted after each test). */
function installPublished(): array
{
    return [
        config_path('admin-core.php'),
        config_path('class.php'),
        resource_path('views/backend'),
        base_path('routes/Web'),
        base_path('routes/Api'),
        app_path('Http/Controllers/Api/AuthController.php'),
        app_path('Providers/ApiAuthServiceProvider.php'),
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
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->create();
PHP);
    File::put(base_path('bootstrap/providers.php'), "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
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

it('adds the HasRoles trait to a User model with extra/reordered traits (Sanctum/Jetstream)', function () {
    File::ensureDirectoryExists(app_path('Models'));
    $userPath = app_path('Models/User.php');
    $original = File::exists($userPath) ? File::get($userPath) : null;

    // A non-default trait line (extra trait, different order) — the old exact-match skipped these.
    File::put($userPath, <<<'PHP'
    <?php

    namespace App\Models;

    use Laravel\Sanctum\HasApiTokens;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Illuminate\Notifications\Notifiable;

    class User extends Authenticatable
    {
        use HasApiTokens, HasFactory, Notifiable;
    }
    PHP);

    // Call the private patcher directly with a buffered output wired in.
    $command = new \Ngos\AdminCore\Console\AdminCoreInstallCommand();
    $command->setLaravel(app());
    (fn ($o) => $this->output = $o)->call(
        $command,
        new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput()),
    );
    $m = new ReflectionMethod($command, 'addHasRolesTrait');
    $m->setAccessible(true);
    $m->invoke($command);

    expect(File::get($userPath))
        ->toContain('use HasApiTokens, HasFactory, Notifiable, HasRoles, HasPublicUuid;') // applied in the class body
        ->toContain('use Spatie\Permission\Traits\HasRoles;')                            // import added
        ->toContain('use Illuminate\Notifications\Notifiable;');                          // FQCN import left intact

    $original === null ? File::delete($userPath) : File::put($userPath, $original);
});

it('scaffolds Passport API auth with --api-auth', function () {
    $this->artisan('admin-core:install', ['--api-auth' => true])->assertSuccessful();

    // Controller + provider published, provider registered.
    expect(File::get(app_path('Http/Controllers/Api/AuthController.php')))
        ->toContain('class AuthController')
        ->toContain("Request::create('/oauth/token', 'POST'");
    expect(File::get(app_path('Providers/ApiAuthServiceProvider.php')))
        ->toContain('Passport::enablePasswordGrant()')
        ->toContain('tokensExpireIn');
    expect(File::get(base_path('bootstrap/providers.php')))->toContain('ApiAuthServiceProvider::class');

    // routes/api.php created with the auth routes + the module loader; bootstrap routed.
    expect(File::get(base_path('routes/api.php')))
        ->toContain('admin-core:api-auth')
        ->toContain("'login'")
        ->toContain("'auth:api'")
        ->toContain('admin-core:api-modules');
    expect(File::get(base_path('bootstrap/app.php')))->toContain("api: __DIR__.'/../routes/api.php'");

    // The published api middleware now points at the Passport guard.
    expect(File::get(config_path('admin-core.php')))->toContain("'auth:api'");
});

it('is idempotent for --api-auth too', function () {
    $this->artisan('admin-core:install', ['--api-auth' => true])->assertSuccessful();
    $this->artisan('admin-core:install', ['--api-auth' => true])->assertSuccessful();

    expect(substr_count(File::get(base_path('routes/api.php')), '>>> admin-core:api-auth'))->toBe(1)
        ->and(substr_count(File::get(base_path('routes/api.php')), '>>> admin-core:api-modules'))->toBe(1)
        ->and(substr_count(File::get(base_path('bootstrap/providers.php')), 'ApiAuthServiceProvider'))->toBe(1);
});
