<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/*
 * The page-header breadcrumb's "Dashboard" crumb must target the CURRENT portal's
 * dashboard (derived from the route name), not always admin.dashboard — otherwise a
 * merchant-portal page links to the admin area (wrong guard).
 */

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('admin/dashboard', fn () => '')->name('admin.dashboard');
        Route::get('merchant/dashboard', fn () => '')->name('merchant.dashboard');
        Route::get('merchant/products', fn () => Blade::render('<x-admin-core::page-header title="Products" />'))
            ->name('merchant.products.index');
        Route::get('admin/users', fn () => Blade::render('<x-admin-core::page-header title="Users" />'))
            ->name('admin.users.index');
    });
    Route::getRoutes()->refreshNameLookups();
});

it('points the Dashboard crumb at the merchant dashboard on a merchant page', function () {
    $this->get('/merchant/products')
        ->assertOk()
        ->assertSee('href="' . route('merchant.dashboard') . '"', false)
        ->assertDontSee('href="' . route('admin.dashboard') . '"', false);
});

it('still points the Dashboard crumb at the admin dashboard on an admin page', function () {
    $this->get('/admin/users')
        ->assertOk()
        ->assertSee('href="' . route('admin.dashboard') . '"', false)
        ->assertDontSee('href="' . route('merchant.dashboard') . '"', false);
});

it('respects an explicit :dashboard override', function () {
    Route::middleware('web')->get('merchant/orders', fn () => Blade::render(
        '<x-admin-core::page-header title="Orders" :dashboard="\'merchant.dashboard\'" />'
    ))->name('admin.orders.index'); // admin route name, but override wins
    Route::getRoutes()->refreshNameLookups();

    $this->get('/merchant/orders')
        ->assertOk()
        ->assertSee('href="' . route('merchant.dashboard') . '"', false);
});
