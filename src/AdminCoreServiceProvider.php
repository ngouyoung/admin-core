<?php

namespace Ngos\AdminCore;

use Closure;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ngos\AdminCore\Console\AdminCoreFieldCommand;
use Ngos\AdminCore\Console\AdminCoreInstallCommand;
use Ngos\AdminCore\Console\AdminCoreMakeCommand;
use Ngos\AdminCore\Console\AdminCorePageCommand;
use Ngos\AdminCore\Console\AdminCorePortalCommand;
use Ngos\AdminCore\Console\AdminCoreReinstallCommand;
use Ngos\AdminCore\Console\AdminCoreUninstallCommand;
use Ngos\AdminCore\Console\AdminCoreVersionCommand;
use Ngos\AdminCore\Http\Controllers\NotificationController;

class AdminCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/admin-core.php', 'admin-core');
        $this->registerCrudMacro();
        $this->registerNotificationsMacro();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'admin-core');
        $this->registerErrorLogging();
        $this->registerErrorLogPruning();

        if ($this->app->runningInConsole()) {
            $this->commands([
                AdminCoreInstallCommand::class,
                AdminCoreMakeCommand::class,
                AdminCoreFieldCommand::class,
                AdminCorePageCommand::class,
                AdminCorePortalCommand::class,
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
     * Capture reported exceptions into the error_logs table (viewable in the admin). Registered as a
     * reportable callback here so no bootstrap/app.php edit is needed; it returns nothing, so the app's
     * normal logging still runs, and {@see ErrorLog::capture()} no-ops when the table isn't installed.
     */
    protected function registerErrorLogging(): void
    {
        $handler = $this->app[\Illuminate\Contracts\Debug\ExceptionHandler::class];

        // The concrete Foundation handler exposes reportable(); guard so a custom handler can't fatal.
        if ($handler instanceof \Illuminate\Foundation\Exceptions\Handler) {
            $handler->reportable(function (\Throwable $e): void {
                \Ngos\AdminCore\Models\ErrorLog::capture($e);
            });
        }
    }

    /**
     * Prune captured errors past their retention window. Registered against the scheduler (resolved only
     * by schedule:run/list, so it costs nothing on a normal request); needs the app's scheduler cron.
     * A retention of 0 disables it. On demand: `model:prune --model="Ngos\AdminCore\Models\ErrorLog"`.
     */
    protected function registerErrorLogPruning(): void
    {
        if ((int) config('admin-core.error_log.retention_days', 30) <= 0) {
            return;
        }

        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule): void {
            $schedule->command('model:prune', ['--model' => [\Ngos\AdminCore\Models\ErrorLog::class]])->daily();
        });
    }

    /**
     * Route::crud('user', UserController::class) — registers the standard CRUD
     * actions, each gated by `permission:{action}-{resource}` per config.
     */
    protected function registerCrudMacro(): void
    {
        Route::macro('crud', function (string $resource, string $controller, ?string $authGuard = null) {
            $enabled = config('admin-core.permission.enabled');

            // For a non-default guard (multi-portal), Spatie's permission middleware must be
            // told the guard (`permission:list-x,merchant`) — otherwise it checks the default.
            $suffix = $authGuard ? ',' . $authGuard : '';

            $permission = fn (string $action) => 'permission:' . str_replace(
                ['{action}', '{resource}'],
                [$action, $resource],
                config('admin-core.permission.pattern')
            ) . $suffix;

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

    /**
     * Route::adminCoreNotifications() — the current user's in-app notification routes
     * (admin.notifications.index/read/readAll/destroy). Call it inside your admin route group.
     */
    protected function registerNotificationsMacro(): void
    {
        Route::macro('adminCoreNotifications', function () {
            Route::controller(NotificationController::class)
                ->prefix('notifications')
                ->name('notifications.')
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('{id}/read', 'read')->name('read');
                    Route::post('read-all', 'readAll')->name('readAll');
                    Route::delete('{id}', 'destroy')->name('destroy');
                });
        });
    }
}
