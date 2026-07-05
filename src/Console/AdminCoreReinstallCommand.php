<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AdminCoreReinstallCommand extends Command
{
    protected $signature = 'admin-core:reinstall
                            {--access : Reinstall the full Bootstrap 5 admin theme + access module}
                            {--api-auth : Reinstall the Passport API-auth feature (auto-detected if already installed)}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Purge then reinstall admin-core — a clean re-scaffold. WARNING: overwrites customised package files.';

    public function handle(): int
    {
        // The uninstall step below purges EVERY feature (theme + api-auth). If we then re-install only what
        // the operator re-typed, a feature they had is silently dropped — reinstall --access on an
        // --access --api-auth install used to delete /api/login, AuthController and the provider for good.
        // So detect what's currently installed BEFORE purging and re-apply it, on top of any explicit flags.
        $hadApiAuth = File::exists(app_path('Http/Controllers/Api/AuthController.php'))
            || File::exists(app_path('Providers/ApiAuthServiceProvider.php'));
        $hadAccess = File::isDirectory(resource_path('views/backend'));

        $this->warn('Reinstall purges admin-core\'s published files and re-scaffolds them. Customisations to those files will be lost.');
        $this->warn('Your admin-core:make-generated resources are NOT touched.');

        if (! $this->option('force') && ! $this->confirm('Continue?', false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->call('admin-core:uninstall', ['--purge' => true, '--force' => true]);

        $this->newLine();
        $this->call('admin-core:install', array_filter([
            '--access' => $this->option('access') || $hadAccess,
            '--api-auth' => $this->option('api-auth') || $hadApiAuth, // preserve, don't drop
            '--force' => true,
        ]));

        return self::SUCCESS;
    }
}
