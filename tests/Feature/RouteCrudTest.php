<?php

use Illuminate\Support\Facades\Route;
use Ngos\AdminCore\Tests\Fixtures\WidgetController;

it('registers every crud route via the macro', function () {
    foreach (['index', 'getData', 'create', 'store', 'edit', 'update', 'delete', 'ajaxDelete', 'action'] as $action) {
        expect(Route::has("admin.widgets.{$action}"))->toBeTrue("missing admin.widgets.{$action}");
    }
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
