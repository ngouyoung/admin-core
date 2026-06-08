<?php

namespace Ngos\AdminCore;

use Closure;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ngos\AdminCore\Console\AdminCoreInstallCommand;
use Ngos\AdminCore\Console\AdminCoreMakeCommand;
use Ngos\AdminCore\Console\AdminCoreReinstallCommand;
use Ngos\AdminCore\Console\AdminCoreUninstallCommand;
use Ngos\AdminCore\Console\AdminCoreVersionCommand;

class AdminCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/admin-core.php', 'admin-core');
        $this->registerCrudMacro();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'admin-core');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AdminCoreInstallCommand::class,
                AdminCoreMakeCommand::class,
                AdminCoreReinstallCommand::class,
                AdminCoreUninstallCommand::class,
                AdminCoreVersionCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/admin-core.php' => config_path('admin-core.php'),
            ], 'admin-core-config');

            $this->publishes([
                __DIR__ . '/../stubs' => base_path('stubs/admin-core'),
            ], 'admin-core-stubs');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/admin-core'),
            ], 'admin-core-views');
        }
    }

    /**
     * Route::crud('user', UserController::class) — registers the standard CRUD
     * actions, each gated by `permission:{action}-{resource}` per config.
     */
    protected function registerCrudMacro(): void
    {
        Route::macro('crud', function (string $resource, string $controller) {
            $enabled = config('admin-core.permission.enabled');

            $permission = fn (string $action) => 'permission:' . str_replace(
                ['{action}', '{resource}'],
                [$action, $resource],
                config('admin-core.permission.pattern')
            );

            $guard = function (string $action, Closure $routes) use ($enabled, $permission) {
                $enabled
                    ? Route::middleware($permission($action))->group($routes)
                    : $routes();
            };

            Route::controller($controller)->group(function () use ($guard) {
                $guard('list', function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('getData', 'getData')->name('getData');
                });
                $guard('create', function () {
                    Route::get('create', 'create')->name('create');
                    Route::post('', 'store')->name('store');
                });
                $guard('edit', function () {
                    Route::get('edit/{id}', 'edit')->name('edit');
                    Route::put('update/{id}', 'update')->name('update');
                });
                $guard('delete', function () {
                    Route::delete('delete/{id}', 'delete')->name('delete');
                    Route::delete('ajaxDelete/{id}', 'ajaxDelete')->name('ajaxDelete');
                });
            });
        });
    }
}
