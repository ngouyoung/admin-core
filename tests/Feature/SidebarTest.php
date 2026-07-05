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

it('hides a menu item whose route needs a required parameter (would 500 every page)', function () {
    // A parameterized route (things/{thing}) can't be built as a plain sidebar link — route($name) throws
    // UrlGenerationException, which, since the sidebar renders on EVERY page, 500s the whole panel (incl. the
    // Menu manager, so the bad item couldn't even be removed via the UI). It must be hidden. An OPTIONAL
    // parameter ({thing?}) builds fine, so it stays.
    \Illuminate\Support\Facades\Route::get('things/{thing}', fn () => '')->name('admin.things.show');
    \Illuminate\Support\Facades\Route::get('opt/{thing?}', fn () => '')->name('admin.things.optional');
    \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();

    $items = Sidebar::items([
        ['label' => 'Widgets', 'route' => 'admin.widgets.index'],    // parameterless → shown
        ['label' => 'Thing', 'route' => 'admin.things.show'],        // required {thing} → hidden (no 500)
        ['label' => 'Optional', 'route' => 'admin.things.optional'], // optional {thing?} → shown
    ]);

    expect(collect($items)->pluck('label')->all())->toBe(['Widgets', 'Optional']);
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

it('enforces a treeview GROUP\'s own can — a restrictive group hides the whole section even if a child passes', function () {
    // A group-level `can` gates the whole section. It used to be ignored (only leaf items were checked), so a
    // group meant for super-admins leaked to anyone a single child let through.
    config(['admin-core.permission.enabled' => true]);
    Gate::define('super-only', fn ($u) => false);   // the group's gate — this user fails it
    Gate::define('list-thing', fn ($u) => true);    // a child the user WOULD otherwise see
    $this->actingAs(new \Illuminate\Foundation\Auth\User);

    $items = Sidebar::items([
        ['label' => 'Admin Zone', 'can' => 'super-only', 'children' => [
            ['label' => 'Thing', 'route' => 'admin.widgets.index', 'can' => 'list-thing'],
        ]],
    ]);

    expect($items)->toBe([]); // the whole group is hidden — not leaked because a child passed

    // A group WITHOUT a can (or one the user passes) still shows its visible children.
    Gate::define('ok', fn ($u) => true);
    $open = Sidebar::items([
        ['label' => 'Open Zone', 'can' => 'ok', 'children' => [
            ['label' => 'Thing', 'route' => 'admin.widgets.index', 'can' => 'list-thing'],
        ]],
    ]);
    expect(collect($open)->pluck('label')->all())->toBe(['Open Zone']);
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

it('marks an item active by an exact match — the dashboard at /admin highlights, deeper paths do not', function () {
    // The Dashboard route lives at /admin, so its match must be 'admin' (no wildcard) — 'admin/dashboard'
    // would never highlight, and 'admin*' would wrongly stay lit on every child page.
    config(['admin-core.menu' => [
        ['label' => 'Dashboard', 'route' => 'admin.widgets.index', 'icon' => 'bi bi-speedometer2', 'match' => 'admin'],
    ]]);

    app()->instance('request', Illuminate\Http\Request::create('admin', 'GET'));
    expect(Blade::render('<x-admin-core::sidebar-menu />'))->toContain('ac-nav-link active');

    app()->instance('request', Illuminate\Http\Request::create('admin/widgets', 'GET'));
    expect(Blade::render('<x-admin-core::sidebar-menu />'))->not->toContain('ac-nav-link active');
});
