<?php

use Illuminate\Support\Facades\File;

/*
 * admin-core:uninstall reverts the host changes install made. The trickiest is the
 * User model trait line: install adds `HasRoles, HasPublicUuid`, so the revert must
 * strip BOTH (and their imports) — leaving a model that doesn't reference a trait it
 * no longer imports (which would be a fatal "Trait not found").
 */

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
