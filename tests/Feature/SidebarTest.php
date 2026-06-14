<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Ngos\AdminCore\Support\Sidebar;

/*
 * The data-driven sidebar: Ngos\AdminCore\Support\Sidebar filters config('admin-core.menu')
 * by route existence + permission, and the admin-core::sidebar-menu component renders it.
 * (admin.widgets.index is a real named route defined in TestCase::defineRoutes.)
 */

it('drops items whose route does not exist and prunes the now-empty header', function () {
    $items = Sidebar::items([
        ['label' => 'Widgets', 'route' => 'admin.widgets.index'],
        ['header' => 'Ghosts'],
        ['label' => 'Ghost', 'route' => 'admin.ghost.index'],   // unregistered route → dropped
    ]);

    expect(collect($items)->pluck('label')->filter()->values()->all())->toBe(['Widgets'])
        ->and(collect($items)->pluck('header')->filter()->all())->toBe([]); // "Ghosts" header pruned
});

it('keeps a header that still has a visible item', function () {
    $items = Sidebar::items([
        ['header' => 'Main'],
        ['label' => 'Widgets', 'route' => 'admin.widgets.index'],
    ]);

    expect(collect($items)->pluck('header')->filter()->all())->toBe(['Main']);
});

it('hides items the user lacks permission for, when permissions are enabled', function () {
    config(['admin-core.permission.enabled' => true]);
    Gate::define('list-open', fn ($u) => true);
    Gate::define('list-secret', fn ($u) => false);
    $this->actingAs(new \Illuminate\Foundation\Auth\User);

    $items = Sidebar::items([
        ['label' => 'Open', 'route' => 'admin.widgets.index', 'can' => 'list-open'],
        ['label' => 'Secret', 'route' => 'admin.widgets.index', 'can' => 'list-secret'],
    ]);

    expect(collect($items)->pluck('label')->all())->toBe(['Open']);
});

it('ignores the can rule entirely when permissions are disabled', function () {
    config(['admin-core.permission.enabled' => false]);

    $items = Sidebar::items([
        ['label' => 'X', 'route' => 'admin.widgets.index', 'can' => 'list-anything'],
    ]);

    expect(collect($items)->pluck('label')->all())->toBe(['X']);
});

it('prunes a treeview whose children are all hidden, keeps one with a visible child', function () {
    $items = Sidebar::items([
        ['label' => 'Empty', 'children' => [
            ['label' => 'GhostChild', 'route' => 'admin.ghost.index'], // dropped → parent dropped
        ]],
        ['label' => 'Full', 'children' => [
            ['label' => 'RealChild', 'route' => 'admin.widgets.index'],
        ]],
    ]);

    expect(collect($items)->pluck('label')->all())->toBe(['Full'])
        ->and($items[0]['children'])->toHaveCount(1);
});

it('filters against the given guard, not just the default one (multi-portal)', function () {
    config([
        'admin-core.permission.enabled' => true,
        'auth.guards.merchant' => ['driver' => 'session', 'provider' => 'users'],
    ]);
    Gate::define('list-thing', fn ($u) => true);
    $this->actingAs(new \Illuminate\Foundation\Auth\User); // logged into the DEFAULT (web) guard only

    $menu = [['label' => 'Thing', 'route' => 'admin.widgets.index', 'can' => 'list-thing']];

    // Default guard: the acting user can → visible. Merchant guard: nobody's logged in → hidden.
    expect(collect(Sidebar::items($menu))->pluck('label')->all())->toBe(['Thing'])
        ->and(collect(Sidebar::items($menu, 'merchant'))->pluck('label')->all())->toBe([]);
});

it('renders a named portal menu via the menu prop', function () {
    config(['admin-core.menus.merchant' => [
        ['label' => 'Storefront', 'route' => 'admin.widgets.index', 'icon' => 'bi bi-shop', 'match' => 'merchant'],
    ]]);

    expect(Blade::render('<x-admin-core::sidebar-menu menu="merchant" />'))
        ->toContain('Storefront')->toContain('ac-nav-item');
});

it('renders the sidebar-menu component, omitting hidden items', function () {
    config(['admin-core.menu' => [
        ['label' => 'Widgets', 'route' => 'admin.widgets.index', 'icon' => 'bi bi-box', 'match' => 'admin/widgets*'],
        ['label' => 'Ghost', 'route' => 'admin.ghost.index'],
    ]]);

    $html = Blade::render('<x-admin-core::sidebar-menu />');

    expect($html)->toContain('Widgets')->toContain('ac-nav-item')->not->toContain('Ghost');
});
