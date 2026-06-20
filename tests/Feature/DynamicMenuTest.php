<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\MenuItem;
use Ngos\AdminCore\Support\Sidebar;

/*
 * Database-driven sidebar menu (menu_source = 'database'): the MenuItem tree builder,
 * its cache, Sidebar::database() filtering, the import command, and the published stubs.
 */

beforeEach(function () {
    Cache::flush();
    Schema::create('menu_items', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('parent_id')->nullable();
        $t->string('label');
        $t->string('icon')->nullable();
        $t->string('route')->nullable();
        $t->string('url')->nullable();
        $t->string('match')->nullable();
        $t->string('permission')->nullable();
        $t->string('target')->nullable();
        $t->unsignedInteger('sort')->default(0);
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });
});

afterEach(fn () => Schema::dropIfExists('menu_items'));

function menuStub(string $rel): string
{
    return File::get(__DIR__ . '/../../stubs/' . $rel);
}

it('builds a nested, sorted tree in the shape the sidebar renders', function () {
    $catalog = MenuItem::create(['label' => 'Catalog', 'sort' => 2]); // header (no link, will gain children)
    MenuItem::create(['label' => 'Products', 'route' => 'admin.products.index', 'icon' => 'bi bi-box', 'parent_id' => $catalog->id, 'sort' => 2]);
    MenuItem::create(['label' => 'Categories', 'url' => '/admin/cats', 'parent_id' => $catalog->id, 'sort' => 1]);
    MenuItem::create(['label' => 'Dashboard', 'route' => 'admin.dashboard', 'sort' => 1]);

    $tree = MenuItem::tree();

    // Roots ordered by sort: Dashboard (1) then Catalog (2).
    expect($tree[0]['label'])->toBe('Dashboard')
        ->and($tree[0]['route'])->toBe('admin.dashboard')
        ->and($tree[1]['label'])->toBe('Catalog')
        ->and($tree[1])->toHaveKey('children');

    // Children ordered by sort: Categories (1, url) then Products (2, route).
    $children = $tree[1]['children'];
    expect($children[0]['label'])->toBe('Categories')
        ->and($children[0]['url'])->toBe('/admin/cats')
        ->and($children[1]['label'])->toBe('Products')
        ->and($children[1]['route'])->toBe('admin.products.index');
});

it('renders a link-less, child-less row as a section header', function () {
    MenuItem::create(['label' => 'System', 'sort' => 1]);

    expect(MenuItem::tree())->toBe([['header' => 'System']]);
});

it('omits inactive items from the tree', function () {
    MenuItem::create(['label' => 'Visible', 'route' => 'admin.dashboard', 'sort' => 1]);
    MenuItem::create(['label' => 'Hidden', 'route' => 'admin.dashboard', 'sort' => 2, 'is_active' => false]);

    expect(collect(MenuItem::tree())->pluck('label')->all())->toBe(['Visible']);
});

it('caches the tree and busts the cache on write', function () {
    MenuItem::create(['label' => 'One', 'route' => 'admin.dashboard', 'sort' => 1]);
    expect(MenuItem::tree())->toHaveCount(1)
        ->and(Cache::has(MenuItem::CACHE_KEY))->toBeTrue();

    MenuItem::create(['label' => 'Two', 'route' => 'admin.dashboard', 'sort' => 2]); // saved() flushes
    expect(MenuItem::tree())->toHaveCount(2);

    MenuItem::query()->delete();
    MenuItem::forgetCache(); // what the reorder endpoint calls after a bulk update
    expect(MenuItem::tree())->toHaveCount(0);
});

it('filters the database menu by route existence (Sidebar::database)', function () {
    // admin.widgets.index is registered by the test harness; the other route does not exist.
    MenuItem::create(['label' => 'Widgets', 'route' => 'admin.widgets.index', 'sort' => 1]);
    MenuItem::create(['label' => 'Ghost', 'route' => 'admin.ghost.index', 'sort' => 2]);

    expect(collect(Sidebar::database())->pluck('label')->all())->toBe(['Widgets']);
});

it('falls back to an empty tree when the table is absent', function () {
    Schema::dropIfExists('menu_items');

    expect(MenuItem::tree())->toBe([]);
});

it('imports the config menu into the table (headers, nesting, permissions)', function () {
    config()->set('admin-core.menu', [
        ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'bi bi-speedometer2'],
        ['header' => 'Shop'],
        ['label' => 'Orders', 'icon' => 'bi bi-receipt', 'match' => 'admin/orders*', 'children' => [
            ['label' => 'All', 'route' => 'admin.orders.index', 'can' => 'list-order'],
        ]],
    ]);

    $this->artisan('admin-core:menu:import')->assertSuccessful();

    expect(MenuItem::count())->toBe(4); // Dashboard, Shop header, Orders, All
    expect(MenuItem::where('label', 'Shop')->first())
        ->route->toBeNull()
        ->url->toBeNull();
    $all = MenuItem::where('label', 'All')->first();
    expect($all->permission)->toBe('list-order')
        ->and($all->parent_id)->toBe(MenuItem::where('label', 'Orders')->first()->id);
});

it('refuses to import over existing rows without --force', function () {
    MenuItem::create(['label' => 'Existing', 'route' => 'admin.dashboard', 'sort' => 1]);
    config()->set('admin-core.menu', [['label' => 'Dashboard', 'route' => 'admin.dashboard']]);

    $this->artisan('admin-core:menu:import')->assertFailed();
    $this->artisan('admin-core:menu:import --force')->assertSuccessful();

    expect(MenuItem::where('label', 'Existing')->exists())->toBeFalse()
        ->and(MenuItem::where('label', 'Dashboard')->exists())->toBeTrue();
});

it('defaults menu_source to config and ships the database switch', function () {
    expect(config('admin-core.menu_source'))->toBe('config');

    // The sidebar-menu component picks the source from the flag.
    expect(File::get(__DIR__ . '/../../resources/views/components/sidebar-menu.blade.php'))
        ->toContain("config('admin-core.menu_source') === 'database'")
        ->toContain('Sidebar::database');
});

it('ships the access stubs for the Menu manager', function () {
    expect(menuStub('access/Http/Controllers/Backend/MenuController.php.stub'))
        ->toContain('class MenuController')
        ->toContain('function reorder')
        ->toContain('MenuItem::forgetCache');

    expect(menuStub('access/routes/account.php.stub'))
        ->toContain("'prefix' => 'menu'")
        ->toContain('permission:manage-menu');

    expect(menuStub('access/database/seeders/AccessSeeder.php.stub'))
        ->toContain("'menu' => ['manage']");

    expect(menuStub('access/views/backend/pages/menu/index.blade.php.stub'))
        ->toContain('id="menu-tree"')
        ->toContain('admin-core:menu:import');

    expect(menuStub('access/database/migrations/0001_01_01_000014_create_menu_items_table.php.stub'))
        ->toContain("Schema::create('menu_items'");
});
