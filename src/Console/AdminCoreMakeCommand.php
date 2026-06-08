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
                            {--fields= : Field DSL, e.g. "name:string, price:decimal?, category_id:foreign"}
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

        $fields = (new FieldSet($this->option('fields')))->setTable($snakePlural);

        $replace = [
            'DummyClasses' => $plural,
            'DummyClass' => $class,
            'dummyModels' => $snakePlural,
            'dummyModel' => $camel,
            'dummy-model' => $kebab,
            '__AC_FILLABLE__' => $fields->fillable(),
            '__AC_MODEL_USES__' => $fields->modelUses(),
            '__AC_RELATIONS__' => $fields->relations(),
            '__AC_COLUMNS__' => $fields->migrationColumns(),
            '__AC_STORE_RULES__' => $fields->storeRules(),
            '__AC_UPDATE_RULES__' => $fields->updateRules(),
            '__AC_UPDATE_USES__' => $fields->updateUses(),
            '__AC_FORM__' => $fields->formFields(),
            '__AC_FORM_SCRIPTS__' => $fields->formScripts(),
            '__AC_THEAD__' => $fields->thead(),
            '__AC_COLS__' => $fields->columnsJs(),
            '__AC_EAGER__' => $fields->eager(),
            '__AC_GETDATA__' => $fields->getDataColumns(),
            '__AC_RAW__' => $fields->rawColumns(),
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
