<?php

namespace Ngos\AdminCore\Tests;

use Illuminate\Support\Facades\Route;
use Ngos\AdminCore\AdminCoreServiceProvider;
use Ngos\AdminCore\Tests\Fixtures\WidgetController;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \Spatie\Permission\PermissionServiceProvider::class,
            \Yajra\DataTables\DataTablesServiceProvider::class,
            AdminCoreServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('admin-core.permission.enabled', false);
        $app['config']->set('admin-core.route.name_prefix', 'admin.');
        $app['config']->set('admin-core.views.path_prefix', 'backend.pages.');
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->prefix('admin')->name('admin.')->group(function () {
            Route::prefix('widgets')->name('widgets.')->group(function () {
                Route::crud('widget', WidgetController::class);
                Route::controller(WidgetController::class)->group(function () {
                    Route::get('show/{id}', 'show')->name('show');
                    Route::get('export', 'export')->name('export');
                    Route::post('bulkDelete', 'bulkDelete')->name('bulkDelete');
                });
            });
        });
    }
}
