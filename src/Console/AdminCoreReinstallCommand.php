<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;

class AdminCoreReinstallCommand extends Command
{
    protected $signature = 'admin-core:reinstall
                            {--access : Reinstall the full AdminLTE 4 front-end + access module}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Purge then reinstall admin-core — a clean re-scaffold. WARNING: overwrites customised package files.';

    public function handle(): int
    {
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
            '--access' => $this->option('access'),
            '--force' => true,
        ]));

        return self::SUCCESS;
    }
}
