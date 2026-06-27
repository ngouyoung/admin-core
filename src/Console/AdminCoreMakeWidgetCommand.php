<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Scaffold a dashboard widget class (Stat / Chart / List) for <x-admin-core::dashboard />. The generated
 * class lands in app/Dashboard and only needs registering in config('admin-core.dashboard.widgets').
 */
class AdminCoreMakeWidgetCommand extends Command
{
    protected $signature = 'admin-core:make-widget
        {name : Widget class name, e.g. Revenue or RevenueWidget}
        {--type=stat : stat | chart | list}
        {--force : Overwrite an existing file}';

    protected $description = 'Scaffold a dashboard widget class (Stat/Chart/List) for the dashboard framework';

    public function handle(Filesystem $files): int
    {
        $type = strtolower((string) $this->option('type'));
        if (! in_array($type, ['stat', 'chart', 'list'], true)) {
            $this->error("Unknown --type '{$type}'. Use stat, chart or list.");

            return self::FAILURE;
        }

        $class = Str::studly($this->argument('name'));
        if (! Str::endsWith($class, 'Widget')) {
            $class .= 'Widget';
        }
        $path = app_path("Dashboard/{$class}.php");

        if ($files->exists($path) && ! $this->option('force')) {
            $this->error("{$path} already exists. Use --force to overwrite.");

            return self::FAILURE;
        }

        $stub = $files->get(__DIR__ . "/../../stubs/dashboard/{$type}-widget.stub");
        $title = Str::headline(Str::beforeLast($class, 'Widget'));

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, strtr($stub, ['{{ class }}' => $class, '{{ title }}' => $title]));

        $this->info("Created {$path}");
        $this->line('  Register it in <comment>config(\'admin-core.dashboard.widgets\')</comment>:');
        $this->line("      \\App\\Dashboard\\{$class}::class,");

        return self::SUCCESS;
    }
}
