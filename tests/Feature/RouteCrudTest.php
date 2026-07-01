<?php

use Illuminate\Support\Facades\Route;
use Ngos\AdminCore\Tests\Fixtures\WidgetController;

it('registers every crud route via the macro', function () {
    foreach (['index', 'getData', 'create', 'store', 'edit', 'update', 'delete', 'ajaxDelete', 'action'] as $action) {
        expect(Route::has("admin.widgets.{$action}"))->toBeTrue("missing admin.widgets.{$action}");
    }
});

it('registers ONLY the read routes for a read-only resource (Route::crud readOnly: true)', function () {
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::crud('report', WidgetController::class, null, true);
        });
    });
    Route::getRoutes()->refreshNameLookups();

    // The read surface is registered…
    foreach (['index', 'getData', 'select'] as $action) {
        expect(Route::has("admin.reports.{$action}"))->toBeTrue("read-only must keep admin.reports.{$action}");
    }
    // …but every write / mutating route is absent.
    foreach (['create', 'store', 'edit', 'update', 'delete', 'ajaxDelete', 'action', 'transition'] as $action) {
        expect(Route::has("admin.reports.{$action}"))->toBeFalse("read-only must NOT register admin.reports.{$action}");
    }
});

it('registers ONLY index + update for a singleton (Route::crudSingleton), gated by edit', function () {
    config()->set('admin-core.permission.enabled', true);
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::prefix('config')->name('config.')->group(function () {
            Route::crudSingleton('config', WidgetController::class);
        });
    });
    Route::getRoutes()->refreshNameLookups();

    // Only the one-record screen's two routes exist…
    expect(Route::has('admin.config.index'))->toBeTrue()
        ->and(Route::has('admin.config.update'))->toBeTrue();
    // …no list/create/delete/getData/show.
    foreach (['create', 'store', 'edit', 'delete', 'getData', 'select', 'show'] as $a) {
        expect(Route::has("admin.config.{$a}"))->toBeFalse("singleton must NOT register admin.config.{$a}");
    }
    // Both gated by edit-config.
    expect(Route::getRoutes()->getByName('admin.config.index')->gatherMiddleware())->toContain('permission:edit-config');
    expect(Route::getRoutes()->getByName('admin.config.update')->gatherMiddleware())->toContain('permission:edit-config');
});

it('registers the action route without route-level permission middleware (runAction gates per-action)', function () {
    $route = Route::getRoutes()->getByName('admin.widgets.action');

    expect($route)->not->toBeNull()
        ->and(collect($route->gatherMiddleware())->contains(fn ($m) => str_contains($m, 'permission:')))->toBeFalse();
});

it('gates routes with permission middleware when enabled', function () {
    config()->set('admin-core.permission.enabled', true);

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::prefix('gadgets')->name('gadgets.')->group(function () {
            Route::crud('gadget', WidgetController::class);
        });
    });

    Route::getRoutes()->refreshNameLookups();
    $route = Route::getRoutes()->getByName('admin.gadgets.index');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('permission:list-gadget');
});

it('does not gate routes when permission is disabled', function () {
    $route = Route::getRoutes()->getByName('admin.widgets.index');

    expect(collect($route->gatherMiddleware())->contains(fn ($m) => str_contains($m, 'permission:')))->toBeFalse();
});
