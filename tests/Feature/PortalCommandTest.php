<?php

use Illuminate\Support\Facades\File;

/*
 * admin-core:portal scaffolds a separate-guard portal (model + login + dashboard + views +
 * Modules dir), so admin-core:make --portal=<name> can generate straight into it.
 */

afterEach(function () {
    // Always remove the temp config fixture (a leftover breaks other suites via mergeConfigFrom).
    File::delete(config_path('admin-core.php'));

    // The portal command writes a guard/provider into config/auth.php — strip the test ones,
    // or they linger in the testbench config and confuse later runs / static analysis.
    $auth = config_path('auth.php');
    if (File::exists($auth)) {
        $cleaned = preg_replace(
            "/^\s*'(shop|shops|depot|depots|merchant|merchants|vendor|vendors|vendor-support|vendor-supports)' => \[.*\],\n/m",
            '',
            File::get($auth),
        );
        if (is_string($cleaned)) {
            File::put($auth, $cleaned);
        }
    }

    foreach (['Shop', 'Depot', 'Merchant', 'Vendor', 'VendorSupport'] as $p) {
        $snake = \Illuminate\Support\Str::snake($p);
        File::delete(app_path("Models/{$p}.php"));
        File::delete(database_path("factories/{$p}Factory.php"));
        File::delete(database_path("seeders/{$p}Seeder.php"));
        File::deleteDirectory(app_path("Http/Controllers/{$p}"));
        File::deleteDirectory(resource_path("views/{$snake}"));
        File::deleteDirectory(base_path("routes/{$p}"));
        foreach (glob(database_path("migrations/*_create_{$snake}s_table.php")) ?: [] as $m) {
            File::delete($m);
        }
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
        ->toContain("route('shop.dashboard')")
        // The rate-limit key is namespaced by THIS portal's guard, so a lockout here can't lock a user out of
        // the admin login (or another portal) — each login surface throttles independently.
        ->toContain("'shop|' . Str::lower(\$request->input('email'))");

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

it('wires multiple portals into the config (menu marker + per-guard super role)', function () {
    $cfg = config_path('admin-core.php');
    File::put($cfg, "<?php\n\nreturn [\n    'menus' => [\n    ],\n    'permission' => [\n        'enabled' => true,\n        'guards' => [],\n    ],\n];\n");

    $this->artisan('admin-core:portal', ['name' => 'shop'])->assertSuccessful();
    $this->artisan('admin-core:portal', ['name' => 'depot'])->assertSuccessful();

    expect(File::get($cfg))
        ->toContain('// admin-core:menu:shop')
        ->toContain('// admin-core:menu:depot')
        ->toContain("'shop' => ['super_role' => 'shop-admin']")     // first portal
        ->toContain("'depot' => ['super_role' => 'depot-admin']");  // second portal — was the bug

    // the edited config must still be valid PHP (cleanup runs in afterEach)
    expect(is_array(require $cfg))->toBeTrue();
});

it('wires a portal named like the config example (merchant) against the REAL published config', function () {
    // The shipped config carries commented-out 'merchant' examples; matching them with str_contains made
    // `admin-core:portal merchant` (the first name everyone tries) a silent no-op. Use the real config.
    File::copy(__DIR__ . '/../../config/admin-core.php', config_path('admin-core.php'));

    $this->artisan('admin-core:portal', ['name' => 'merchant'])->assertSuccessful();

    // The REAL (uncommented, line-start) menu key + super-role were added — not just the existing comments.
    expect(File::get(config_path('admin-core.php')))
        ->toMatch('/^[ \t]*\x27merchant\x27 => \[$/m')
        ->toMatch('/^[ \t]*\x27merchant\x27 => \[\x27super_role\x27 => \x27merchant-admin\x27\]/m');
});

it('is idempotent — existing portal files are skipped on a re-run', function () {
    $this->artisan('admin-core:portal', ['name' => 'shop'])->assertSuccessful();
    $this->artisan('admin-core:portal', ['name' => 'shop'])
        ->expectsOutputToContain('exists')
        ->assertSuccessful();

    // Still exactly one create migration (never duplicated).
    expect(glob(database_path('migrations/*_create_shops_table.php')))->toHaveCount(1);
});


it('appends a portal route group even when a longer-named portal marker already exists (anchored idempotency)', function () {
    // A bare str_contains made `portal vendor` see the existing "admin-core:portal:vendor-support" marker as
    // itself and skip writing its route group — so /vendor/login 404'd though model/guard/menu were scaffolded.
    $web = base_path('routes/web.php');
    $original = File::exists($web) ? File::get($web) : null;

    try {
        File::ensureDirectoryExists(dirname($web));
        File::put($web, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n"
            . "// >>> admin-core:portal:vendor-support (managed by admin-core:portal — do not edit the markers)\n"
            . "// <<< admin-core:portal:vendor-support\n");

        $this->artisan('admin-core:portal', ['name' => 'vendor'])->assertSuccessful();

        $after = File::get($web);
        expect($after)->toContain('>>> admin-core:portal:vendor (')     // vendor's own group WAS appended
            ->toContain("prefix('vendor')")                             // the login/logout routes exist now
            ->toContain('>>> admin-core:portal:vendor-support (');      // the pre-existing one is untouched
    } finally {
        $original === null ? File::delete($web) : File::put($web, $original);
    }
});

it('refuses a portal name that would scaffold broken PHP, writing nothing', function () {
    $before = \Illuminate\Support\Facades\File::glob(app_path('Models/*'));

    foreach (["O'Brien", '2024 shop'] as $bad) {
        $this->artisan('admin-core:portal', ['name' => $bad])->assertFailed();
    }

    expect(\Illuminate\Support\Facades\File::glob(app_path('Models/*')))->toBe($before);
});
