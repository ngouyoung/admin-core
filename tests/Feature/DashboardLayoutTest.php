<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Dashboard\Dashboard;
use Ngos\AdminCore\Models\DashboardLayout;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/* Per-user dashboard layouts: the saved arrangement (order + hidden) and the save endpoint. */

beforeEach(function () {
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->timestamps();
    });
    Schema::create('dashboard_layouts', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->string('guard')->nullable();
        $t->json('layout');
        $t->timestamps();
        $t->unique(['user_id', 'guard']);
    });
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Route::adminCoreDashboard());
    Route::getRoutes()->refreshNameLookups(); // tests add routes after boot; refresh so Route::has() sees them
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'key' => 'a', 'title' => 'Alpha', 'value' => fn () => 1],
        ['type' => 'stat', 'key' => 'b', 'title' => 'Beta', 'value' => fn () => 2],
        ['type' => 'stat', 'key' => 'c', 'title' => 'Gamma', 'value' => fn () => 3],
    ]]);
});

afterEach(function () {
    Schema::dropIfExists('dashboard_layouts');
    Schema::dropIfExists('users');
});

it('arranges widgets by the user saved order and hides the hidden ones', function () {
    $user = NotifiableUser::create(['name' => 'U']);
    DashboardLayout::create(['user_id' => $user->id, 'guard' => 'web', 'layout' => ['order' => ['c', 'a'], 'hidden' => ['b']]]);

    $this->actingAs($user);

    expect(app(Dashboard::class)->arranged()->map(fn ($w) => $w->key())->all())->toBe(['c', 'a']);
});

it('falls back to the declared order when the user has no saved layout', function () {
    $this->actingAs(NotifiableUser::create(['name' => 'U']));

    expect(app(Dashboard::class)->arranged()->map(fn ($w) => $w->key())->all())->toBe(['a', 'b', 'c']);
});

it('appends widgets added since the layout was saved', function () {
    $user = NotifiableUser::create(['name' => 'U']);
    DashboardLayout::create(['user_id' => $user->id, 'guard' => 'web', 'layout' => ['order' => ['b'], 'hidden' => []]]);

    $this->actingAs($user);

    // 'b' was saved first; 'a' and 'c' (added since) follow in declared order.
    expect(app(Dashboard::class)->arranged()->map(fn ($w) => $w->key())->all())->toBe(['b', 'a', 'c']);
});

it('persists a layout through the save endpoint', function () {
    $user = NotifiableUser::create(['name' => 'U']);

    $this->actingAs($user)
        ->postJson('/admin/dashboard/layout', ['order' => ['c', 'b', 'a'], 'hidden' => ['a']])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect(DashboardLayout::where('user_id', $user->id)->first()->layout)
        ->toBe(['order' => ['c', 'b', 'a'], 'hidden' => ['a']]);
});

it('filters a saved layout down to real widget keys', function () {
    $user = NotifiableUser::create(['name' => 'U']);
    $this->actingAs($user);

    app(Dashboard::class)->saveLayout(['a', 'bogus', 'b'], ['ghost', 'c']);

    $layout = DashboardLayout::where('user_id', $user->id)->first()->layout;
    expect($layout['order'])->toBe(['a', 'b'])   // unknown 'bogus' dropped
        ->and($layout['hidden'])->toBe(['c']);   // unknown 'ghost' dropped
});

it('saves + reads a portal (non-default-guard) user\'s layout, namespaced by guard', function () {
    config()->set('admin-core.permission.guards', ['merchant' => []]);
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);

    // A merchant-guard user authenticated on the MERCHANT guard only (default guard untouched) — auth()->id()
    // would be null here, so the old code silently no-op'd the save. currentUser() must resolve them.
    $merchant = NotifiableUser::create(['name' => 'Merchant']);
    auth()->guard('merchant')->setUser($merchant);

    app(Dashboard::class)->saveLayout(['b', 'a'], ['c']);

    // Persisted under the merchant guard, and read back for the same guard.
    $row = DashboardLayout::where('user_id', $merchant->id)->where('guard', 'merchant')->first();
    expect($row)->not->toBeNull()
        ->and($row->layout['order'])->toBe(['b', 'a'])
        ->and(app(Dashboard::class)->layout()['order'])->toBe(['b', 'a']);

    // A WEB user with the SAME id must NOT see the merchant layout (no cross-guard collision).
    $web = NotifiableUser::find($merchant->id) ?? NotifiableUser::create(['name' => 'Web']);
    auth()->guard('merchant')->logout();
    $this->actingAs($web); // default (web) guard
    expect(app(Dashboard::class)->layout())->toBe([]); // no merchant row leaks to the web guard
});

it('backfills a pre-existing (guard-less) layout to the default guard on upgrade, keeping it reachable', function () {
    // Rebuild the table in the OLD pre-guard shape and seed a row the way an existing install had it.
    Schema::dropIfExists('dashboard_layouts');
    Schema::create('dashboard_layouts', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id')->unique();
        $t->json('layout');
        $t->timestamps();
    });
    $user = NotifiableUser::create(['name' => 'U']);
    \Illuminate\Support\Facades\DB::table('dashboard_layouts')->insert([
        'user_id' => $user->id,
        'layout' => json_encode(['order' => ['b', 'a'], 'hidden' => ['c']]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Run the shipped upgrade migration.
    (require dirname(__DIR__, 2) . '/database/migrations/2025_01_01_000006_add_guard_to_dashboard_layouts_table.php')->up();

    // The pre-existing row is backfilled to the default guard, so the user still sees their saved arrangement.
    expect(DashboardLayout::where('user_id', $user->id)->value('guard'))->toBe('web');
    $this->actingAs($user);
    expect(app(Dashboard::class)->layout()['order'])->toBe(['b', 'a']);
});

it('shows the Customize button for an authenticated user', function () {
    $this->actingAs(NotifiableUser::create(['name' => 'U']));

    expect(\Illuminate\Support\Facades\Blade::render('<x-admin-core::dashboard />'))
        ->toContain('data-ac-customize')
        ->toContain('data-ac-layout-url');
});
