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

it('lists the --api-auth files among the purge targets (so --purge deletes them, not orphans)', function () {
    $command = new \Ngos\AdminCore\Console\AdminCoreUninstallCommand();
    $owned = (new ReflectionMethod($command, 'ownedFiles'))->invoke($command);

    expect($owned)
        ->toContain(app_path('Http/Controllers/Api/AuthController.php'))
        ->toContain(app_path('Providers/ApiAuthServiceProvider.php'));
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
