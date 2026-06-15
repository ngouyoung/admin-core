<?php

use Illuminate\Support\Facades\File;

/*
 * admin-core:portal scaffolds a separate-guard portal (model + login + dashboard + views +
 * Modules dir), so admin-core:make --portal=<name> can generate straight into it.
 */

afterEach(function () {
    File::delete(app_path('Models/Shop.php'));
    File::delete(database_path('factories/ShopFactory.php'));
    File::delete(database_path('seeders/ShopSeeder.php'));
    File::deleteDirectory(app_path('Http/Controllers/Shop'));
    File::deleteDirectory(resource_path('views/shop'));
    File::deleteDirectory(base_path('routes/Shop'));
    foreach (glob(database_path('migrations/*_create_shops_table.php')) ?: [] as $m) {
        File::delete($m);
    }
});

it('scaffolds a separate-guard portal (model + login + dashboard)', function () {
    $this->artisan('admin-core:portal', ['name' => 'shop'])->assertSuccessful();

    // User model scoped to the portal's guard, password hidden.
    expect(File::get(app_path('Models/Shop.php')))
        ->toContain('class Shop extends Authenticatable')
        ->toContain("\$guard_name = 'shop'")
        ->toContain("protected \$hidden = ['password', 'remember_token']");

    // Login authenticates on the shop guard and lands on its dashboard.
    expect(File::get(app_path('Http/Controllers/Shop/Auth/LoginController.php')))
        ->toContain("Auth::guard('shop')->attempt")
        ->toContain("route('shop.dashboard')");

    // Dashboard renders this portal's permission-filtered menu via the component.
    expect(File::get(resource_path('views/shop/layout.blade.php')))
        ->toContain('menu="shop" guard="shop"');
    expect(File::exists(resource_path('views/shop/auth/login.blade.php')))->toBeTrue()
        ->and(File::exists(resource_path('views/shop/dashboard.blade.php')))->toBeTrue();

    // The Modules dir admin-core:make --portal=shop writes into.
    expect(File::isDirectory(base_path('routes/Shop/Modules')))->toBeTrue();

    // A create migration for the portal's user table.
    expect(glob(database_path('migrations/*_create_shops_table.php')))->not->toBeEmpty();

    // A seeder that makes the portal loggable out of the box: a default account + a
    // guard-scoped super role granted that guard's permissions.
    expect(File::get(database_path('seeders/ShopSeeder.php')))
        ->toContain("'shop@example.com'")
        ->toContain("'name' => 'shop-admin'")
        ->toContain("'guard_name' => 'shop'")
        ->toContain("where('guard_name', 'shop')");
    expect(File::exists(database_path('factories/ShopFactory.php')))->toBeTrue();
});

it('is idempotent — existing portal files are skipped on a re-run', function () {
    $this->artisan('admin-core:portal', ['name' => 'shop'])->assertSuccessful();
    $this->artisan('admin-core:portal', ['name' => 'shop'])
        ->expectsOutputToContain('exists')
        ->assertSuccessful();

    // Still exactly one create migration (never duplicated).
    expect(glob(database_path('migrations/*_create_shops_table.php')))->toHaveCount(1);
});
