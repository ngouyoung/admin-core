<?php

use Illuminate\Support\Facades\File;

/*
 * admin-core:uninstall reverts the host changes install made. The trickiest is the
 * User model trait line: install adds `HasRoles, HasPublicUuid`, so the revert must
 * strip BOTH (and their imports) — leaving a model that doesn't reference a trait it
 * no longer imports (which would be a fatal "Trait not found").
 */

it('strips the api-auth + api-modules blocks from routes/api.php (host content preserved)', function () {
    $api = base_path('routes/api.php');
    $original = File::exists($api) ? File::get($api) : null;
    File::ensureDirectoryExists(dirname($api));
    File::put($api, <<<'PHP'
    <?php

    use Illuminate\Support\Facades\Route;

    Route::get('health', fn () => 'ok'); // host's own route — must survive

    // >>> admin-core:api-auth
    Route::post('login', [App\Http\Controllers\Api\AuthController::class, 'login']);
    // <<< admin-core:api-auth

    // >>> admin-core:api-modules
    foreach (glob(__DIR__ . '/Api/Modules/*.php') ?: [] as $m) { require $m; }
    // <<< admin-core:api-modules
    PHP);

    $command = new \Ngos\AdminCore\Console\AdminCoreUninstallCommand();
    $strip = new ReflectionMethod($command, 'stripBlock');
    $strip->invoke($command, $api, 'admin-core:api-auth');
    $strip->invoke($command, $api, 'admin-core:api-modules');

    expect(File::get($api))
        ->not->toContain('admin-core:api-auth')
        ->not->toContain('admin-core:api-modules')
        ->not->toContain('AuthController')          // no reference to the purged controller
        ->toContain("Route::get('health'");          // the host's own route is preserved

    $original === null ? File::delete($api) : File::put($api, $original);
});

it('un-registers the --api-auth provider from bootstrap/providers.php (host providers kept)', function () {
    // --api-auth registers App\Providers\ApiAuthServiceProvider here. Un-wiring must drop it, or --purge
    // deletes the provider file and leaves a registration pointing at a missing class → fatal on next boot.
    $path = base_path('bootstrap/providers.php');
    $original = File::exists($path) ? File::get($path) : null;
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'PHP'
    <?php

    return [
        App\Providers\AppServiceProvider::class,
        App\Providers\ApiAuthServiceProvider::class,
    ];
    PHP);

    $command = new \Ngos\AdminCore\Console\AdminCoreUninstallCommand();
    (fn ($o) => $this->output = $o)->call(
        $command,
        new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput()),
    );
    (new ReflectionMethod($command, 'unregisterApiAuthProvider'))->invoke($command);

    expect(File::get($path))
        ->not->toContain('ApiAuthServiceProvider')                  // admin-core's registration is gone
        ->toContain('App\Providers\AppServiceProvider::class,');     // the host's own provider survives

    $original === null ? File::delete($path) : File::put($path, $original);
});

it('warns that --purge deletes customised files too (informed consent), and aborts on "no"', function () {
    $this->artisan('admin-core:uninstall', ['--purge' => true])
        ->expectsOutputToContain('INCLUDING any local edits you made to them')
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutput('Aborted.')
        ->assertExitCode(0);
});

it('excludes the frontend/access tree from purge targets on a minimal install (no framework file deletion)', function () {
    File::deleteDirectory(resource_path('js')); // ensure no frontend-kit signal → a minimal install
    $command = new \Ngos\AdminCore\Console\AdminCoreUninstallCommand();
    $owned = (new ReflectionMethod($command, 'ownedFiles'))->invoke($command);

    // The framework-owned resources/js/app.js must NOT be a purge target; the minimal config IS ours.
    expect($owned)->not->toContain(resource_path('js/app.js'))
        ->and($owned)->toContain(config_path('admin-core.php'))
        // The three fixed-name migrations install copies on every install belong to the base purge list
        // (not the --access-only tree) — else --purge orphans them.
        ->and($owned)->toContain(database_path('migrations/0001_01_01_000020_create_activity_logs_table.php'))
        ->and($owned)->toContain(database_path('migrations/0001_01_01_000021_create_notifications_table.php'))
        ->and($owned)->toContain(database_path('migrations/0001_01_01_000022_create_error_logs_table.php'))
        // account.php is --access-only, so it must NOT be claimed on a minimal install.
        ->and($owned)->not->toContain(base_path('routes/Web/Backend/Modules/account.php'))
        // The auth views are --access-only (installFrontend) AND share Breeze's paths, so on a minimal install
        // they must NOT be purge targets — else --purge deletes the host's own login view (breaking /login).
        ->and($owned)->not->toContain(resource_path('views/auth/login.blade.php'))
        ->and($owned)->not->toContain(resource_path('views/auth/two-factor-challenge.blade.php'));
});

it('claims the frontend/access tree when the kit IS installed', function () {
    File::ensureDirectoryExists(resource_path('js'));
    File::copy(dirname(__DIR__, 2) . '/stubs/frontend/resources/js/datatable.js.stub', resource_path('js/datatable.js'));

    $command = new \Ngos\AdminCore\Console\AdminCoreUninstallCommand();
    $owned = (new ReflectionMethod($command, 'ownedFiles'))->invoke($command);

    expect($owned)->toContain(resource_path('js/app.js'))                                 // now ours to purge
        ->and($owned)->toContain(base_path('routes/Web/Backend/Modules/account.php'))     // the --access account route too
        ->and($owned)->toContain(resource_path('views/auth/login.blade.php'))             // and the --access auth views
        ->and($owned)->toContain(resource_path('views/auth/two-factor-challenge.blade.php'));

    File::deleteDirectory(resource_path('js'));
});

it('claims the --api-auth files as purge targets only when the api-auth kit was installed here', function () {
    // install --api-auth publishes ApiAuthServiceProvider.php — the install-here signal (unwire only un-registers
    // it, never deletes the file). With the signal present, both api-auth files are purge targets.
    File::ensureDirectoryExists(app_path('Providers'));
    File::put(app_path('Providers/ApiAuthServiceProvider.php'), '<?php');

    $command = new \Ngos\AdminCore\Console\AdminCoreUninstallCommand();
    $owned = (new ReflectionMethod($command, 'ownedFiles'))->invoke($command);

    expect($owned)
        ->toContain(app_path('Http/Controllers/Api/AuthController.php'))
        ->toContain(app_path('Providers/ApiAuthServiceProvider.php'));

    File::delete(app_path('Providers/ApiAuthServiceProvider.php'));
});

it('does NOT purge a host-owned Api/AuthController when --api-auth was never installed (no provider signal)', function () {
    // The bug: app/Http/Controllers/Api/AuthController.php is a CONVENTIONAL host path. A host owning its own API
    // auth controller, with admin-core installed WITHOUT --api-auth (so no ApiAuthServiceProvider.php), must not
    // have that file deleted by --purge — admin-core never created it (install even refuses to overwrite it).
    if (File::exists(app_path('Providers/ApiAuthServiceProvider.php'))) {
        File::delete(app_path('Providers/ApiAuthServiceProvider.php')); // ensure no install-here signal
    }

    $command = new \Ngos\AdminCore\Console\AdminCoreUninstallCommand();
    $owned = (new ReflectionMethod($command, 'ownedFiles'))->invoke($command);

    expect($owned)
        ->not->toContain(app_path('Http/Controllers/Api/AuthController.php'))
        ->not->toContain(app_path('Providers/ApiAuthServiceProvider.php'));
});

it('reverts HasRoles/HasPublicUuid cleanly (no dangling trait or import)', function () {
    File::ensureDirectoryExists(app_path('Models'));
    $userPath = app_path('Models/User.php');
    $original = File::exists($userPath) ? File::get($userPath) : null;

    // Exactly what `install --access` leaves on a Sanctum User model.
    File::put($userPath, <<<'PHP'
    <?php

    namespace App\Models;

    use Laravel\Sanctum\HasApiTokens;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Illuminate\Notifications\Notifiable;
    use Ngos\AdminCore\Concerns\HasPublicUuid;
    use Spatie\Permission\Traits\HasRoles;

    class User extends Authenticatable
    {
        use HasApiTokens, HasFactory, Notifiable, HasRoles, HasPublicUuid;
    }
    PHP);

    $command = new \Ngos\AdminCore\Console\AdminCoreUninstallCommand();
    $command->setLaravel(app());
    (fn ($o) => $this->output = $o)->call(
        $command,
        new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput()),
    );
    (new ReflectionMethod($command, 'revertHasRoles'))->invoke($command);

    expect(File::get($userPath))
        ->toContain('use HasApiTokens, HasFactory, Notifiable;') // the two traits stripped, others kept
        ->not->toContain('HasRoles')                            // neither the trait nor its import survive
        ->not->toContain('HasPublicUuid');

    $original === null ? File::delete($userPath) : File::put($userPath, $original);
});
