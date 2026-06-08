<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class AdminCoreMakeCommand extends Command
{
    protected $signature = 'admin-core:make {name : The resource name, e.g. Product}
                            {--migration : Also generate a create migration}
                            {--force : Overwrite existing files}';

    protected $description = 'Scaffold a full admin-core CRUD resource (model, service, controller, requests, routes, views, permissions).';

    public function handle(): int
    {
        $class = Str::studly(Str::singular($this->argument('name')));
        $plural = Str::plural($class);
        $camel = Str::camel($class);
        $snakePlural = Str::snake(Str::pluralStudly($class));
        $kebab = Str::kebab($class);

        $replace = [
            'DummyClasses' => $plural,
            'DummyClass' => $class,
            'dummyModels' => $snakePlural,
            'dummyModel' => $camel,
            'dummy-model' => $kebab,
        ];

        $files = [
            'model.stub' => app_path("Models/{$class}.php"),
            'service.stub' => app_path("Services/{$plural}/{$class}Service.php"),
            'controller.stub' => app_path("Http/Controllers/Backend/{$class}Controller.php"),
            'store-request.stub' => app_path("Http/Requests/{$class}/Store{$class}Request.php"),
            'update-request.stub' => app_path("Http/Requests/{$class}/Update{$class}Request.php"),
            'routes.stub' => base_path("routes/Web/Backend/Modules/{$snakePlural}.php"),
            'views/index.stub' => resource_path("views/backend/pages/{$snakePlural}/index.blade.php"),
            'views/create.stub' => resource_path("views/backend/pages/{$snakePlural}/create.blade.php"),
            'views/edit.stub' => resource_path("views/backend/pages/{$snakePlural}/edit.blade.php"),
            'views/form.stub' => resource_path("views/backend/pages/{$snakePlural}/partials/form.blade.php"),
            'views/thead.stub' => resource_path("views/backend/pages/{$snakePlural}/partials/thead.blade.php"),
            'views/scripts.stub' => resource_path("views/backend/pages/{$snakePlural}/partials/scripts.blade.php"),
        ];

        if ($this->option('migration')) {
            $files['migration.stub'] = base_path('database/migrations/' . date('Y_m_d_His') . "_create_{$snakePlural}_table.php");
        }

        $stubBase = $this->stubPath();

        foreach ($files as $stub => $target) {
            if (File::exists($target) && ! $this->option('force')) {
                $this->warn('Skipped (exists): ' . $this->relative($target));
                continue;
            }
            File::ensureDirectoryExists(dirname($target));
            File::put($target, strtr(File::get("{$stubBase}/{$stub}"), $replace));
            $this->line('  <info>created</info> ' . $this->relative($target));
        }

        $this->createPermissions($kebab);

        $this->newLine();
        $this->info("Resource '{$class}' scaffolded.");
        $this->line("  Route:  /admin/{$snakePlural}   (name: admin.{$snakePlural}.*)");
        $this->line('  Assign the new permissions to a role, then visit the page.');

        return self::SUCCESS;
    }

    /** Published stubs (base_path) win over the package's own, so projects can customise them. */
    private function stubPath(): string
    {
        $published = base_path('stubs/admin-core');

        return File::isDirectory($published) ? $published : __DIR__ . '/../../stubs';
    }

    private function createPermissions(string $kebab): void
    {
        if (! config('admin-core.permission.enabled') || ! Schema::hasTable('permissions')) {
            return;
        }

        $model = config('admin-core.permission.model', \Spatie\Permission\Models\Permission::class);

        foreach (['list', 'create', 'edit', 'delete'] as $action) {
            $model::firstOrCreate(['name' => "{$action}-{$kebab}", 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->line("  <info>permissions</info> list/create/edit/delete-{$kebab}");
    }

    private function relative(string $path): string
    {
        return Str::after($path, base_path() . DIRECTORY_SEPARATOR);
    }
}
