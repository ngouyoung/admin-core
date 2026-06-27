<?php

namespace Ngos\AdminCore;

use Closure;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ngos\AdminCore\Console\AdminCoreDoctorCommand;
use Ngos\AdminCore\Console\AdminCoreFieldCommand;
use Ngos\AdminCore\Console\AdminCoreInstallCommand;
use Ngos\AdminCore\Console\AdminCoreMakeCommand;
use Ngos\AdminCore\Console\AdminCoreMakeWidgetCommand;
use Ngos\AdminCore\Console\AdminCoreMenuImportCommand;
use Ngos\AdminCore\Console\AdminCorePageCommand;
use Ngos\AdminCore\Console\AdminCorePortalCommand;
use Ngos\AdminCore\Console\AdminCoreReinstallCommand;
use Ngos\AdminCore\Console\AdminCoreTranslateCommand;
use Ngos\AdminCore\Console\AdminCoreUninstallCommand;
use Ngos\AdminCore\Console\AdminCoreVersionCommand;
use Ngos\AdminCore\Http\Controllers\DashboardController;
use Ngos\AdminCore\Http\Controllers\MediaController;
use Ngos\AdminCore\Http\Controllers\NotificationController;
use Ngos\AdminCore\Http\Controllers\SearchController;
use Ngos\AdminCore\Http\Middleware\AutoTranslate;
use Ngos\AdminCore\Http\Middleware\RequireTwoFactor;
use Ngos\AdminCore\Http\Middleware\SetLocale;
use Ngos\AdminCore\Translation\TranslationManager;
use Ngos\AdminCore\Translation\Translator;

class AdminCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/admin-core.php', 'admin-core');
        $this->registerCrudMacro();
        $this->registerNotificationsMacro();
        $this->registerSearchMacro();
        $this->registerDashboardMacro();
        $this->registerMediaMacro();

        // The configured translation driver, resolved through the manager so
        // config('admin-core.translation.driver') is the only switch.
        $this->app->singleton(TranslationManager::class);
        $this->app->bind(Translator::class, fn ($app) => $app->make(TranslationManager::class)->driver());
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'admin-core');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations'); // package-owned tables (e.g. dashboard_layouts)
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'admin-core');
        $this->registerLocalization();
        $this->registerTwoFactor();
        $this->registerErrorLogging();
        $this->registerErrorLogPruning();

        if ($this->app->runningInConsole()) {
            $this->commands([
                AdminCoreInstallCommand::class,
                AdminCoreMakeCommand::class,
                AdminCoreMakeWidgetCommand::class,
                AdminCoreFieldCommand::class,
                AdminCorePageCommand::class,
                AdminCoreDoctorCommand::class,
                AdminCoreMenuImportCommand::class,
                AdminCorePortalCommand::class,
                AdminCoreReinstallCommand::class,
                AdminCoreTranslateCommand::class,
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

            $this->publishes([
                __DIR__ . '/../lang' => $this->app->langPath('vendor/admin-core'),
            ], 'admin-core-lang');
        }
    }

    /**
     * Register the localization middleware on the `web` group so it applies to every admin page with no
     * route changes in the host (works in already-installed apps). SetLocale sets the per-user language;
     * AutoTranslate fills empty per-locale fields on save — both no-op when not needed, so global is free.
     */
    protected function registerLocalization(): void
    {
        Route::aliasMiddleware('admin-core.locale', SetLocale::class);
        Route::aliasMiddleware('admin-core.translate', AutoTranslate::class);
        Route::pushMiddlewareToGroup('web', SetLocale::class);
        Route::pushMiddlewareToGroup('web', AutoTranslate::class);
    }

    /**
     * Register the 2FA enforcement middleware on the `web` group (no host route changes needed). It is a
     * no-op unless `admin-core.two_factor.enabled` and `.enforce` are both on, so global registration is
     * free; when enforced it redirects any admin without confirmed 2FA to their profile to set it up.
     */
    protected function registerTwoFactor(): void
    {
        Route::aliasMiddleware('admin-core.2fa', RequireTwoFactor::class);
        Route::pushMiddlewareToGroup('web', RequireTwoFactor::class);
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
                    Route::get('select', 'select')->name('select'); // Select2 remote source (search + paginate)
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

    /**
     * Route::adminCoreSearch() — the global-search endpoint (admin.search), surfaced by the
     * <x-admin-core::global-search /> topbar component. Call it inside your admin route group.
     * Searches config('admin-core.search') with LIKE — no external search engine / dependency.
     */
    protected function registerSearchMacro(): void
    {
        Route::macro('adminCoreSearch', function () {
            Route::get('search', [SearchController::class, 'index'])->name('search');
        });
    }

    /**
     * Route::adminCoreDashboard() — the dashboard widget framework's AJAX endpoint
     * (admin.dashboard.widget), used by lazy-load + auto-refresh of <x-admin-core::dashboard /> widgets.
     * Call it inside your admin route group (admin-core:install adds it).
     */
    protected function registerDashboardMacro(): void
    {
        Route::macro('adminCoreDashboard', function () {
            Route::get('dashboard/widget/{key}', [DashboardController::class, 'widget'])->name('dashboard.widget');
            Route::post('dashboard/layout', [DashboardController::class, 'saveLayout'])->name('dashboard.layout');
        });
    }

    /**
     * Route::adminCoreMedia() — the media library screen + upload/delete endpoints (admin.media.*), surfaced
     * by the sidebar "Media" link and the <x-admin-core::media-picker /> field. Permission-gated by
     * `manage-media` when permissions are enabled. Call it inside your admin route group (admin-core:install adds it).
     */
    protected function registerMediaMacro(): void
    {
        Route::macro('adminCoreMedia', function () {
            $middleware = config('admin-core.permission.enabled') ? ['permission:manage-media'] : [];
            Route::controller(MediaController::class)
                ->prefix('media')
                ->name('media.')
                ->middleware($middleware)
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('list', 'list')->name('list'); // JSON list for the media picker modal
                    Route::post('upload', 'upload')->name('upload');
                    Route::delete('{media}', 'destroy')->name('destroy');
                });
        });
    }
}
