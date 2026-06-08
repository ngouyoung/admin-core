<?php

namespace Ngos\AdminCore\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AdminCoreVersionCommand extends Command
{
    protected $signature = 'admin-core:version';

    protected $description = 'Show the installed ngos/admin-core package version.';

    public function handle(): int
    {
        $this->line('<info>ngos/admin-core</info> ' . $this->version());

        return self::SUCCESS;
    }

    private function version(): string
    {
        // Preferred: Composer's runtime API.
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('ngos/admin-core')) {
            return InstalledVersions::getPrettyVersion('ngos/admin-core') ?? 'dev';
        }

        // Fallback: read the package composer.json.
        $manifest = __DIR__ . '/../../composer.json';
        if (File::exists($manifest)) {
            $data = json_decode(File::get($manifest), true);

            return $data['version'] ?? 'unknown';
        }

        return 'unknown';
    }
}
