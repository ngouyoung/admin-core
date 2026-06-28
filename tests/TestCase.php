<?php

namespace Ngos\AdminCore\Tests;

use Illuminate\Support\Facades\Route;
use Ngos\AdminCore\AdminCoreServiceProvider;
use Ngos\AdminCore\Tests\Fixtures\ActionWidgetController;
use Ngos\AdminCore\Tests\Fixtures\HybridWidgetController;
use Ngos\AdminCore\Tests\Fixtures\WidgetApiController;
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
                    Route::get('import-template', 'importTemplate')->name('importTemplate');
                    Route::post('import', 'import')->name('import');
                    Route::post('bulkDelete', 'bulkDelete')->name('bulkDelete');
                    Route::post('reorder', 'reorder')->name('reorder');
                });
            });

            // Hybrid-key variant (bigint id + public uuid route key) — exercises
            // the resolve-by-uuid path the generator produces by default.
            Route::prefix('hybrid-widgets')->name('hybrid_widgets.')->group(function () {
                Route::crud('hybrid-widget', HybridWidgetController::class);
            });

            // Declarative table actions + field-level permissions. Routes bake with permission disabled
            // (no spatie middleware), so a test can flip permission.enabled at runtime and reach runAction.
            Route::prefix('action-widgets')->name('actionWidgets.')->group(function () {
                Route::crud('action-widget', ActionWidgetController::class);
            });

            // The approvals inbox (approve / reject the requests that ->requiresApproval() actions create).
            Route::adminCoreApprovals();
        });

        // JSON API index (top-level, like a real api.php module) — exercises
        // ApiController's search/sort/filter list query.
        Route::middleware('web')->get('api/widgets', [WidgetApiController::class, 'index'])->name('api.widgets.index');
    }
}
