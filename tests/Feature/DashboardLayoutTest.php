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
        $t->unsignedBigInteger('user_id')->unique();
        $t->json('layout');
        $t->timestamps();
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
    DashboardLayout::create(['user_id' => $user->id, 'layout' => ['order' => ['c', 'a'], 'hidden' => ['b']]]);

    $this->actingAs($user);

    expect(app(Dashboard::class)->arranged()->map(fn ($w) => $w->key())->all())->toBe(['c', 'a']);
});

it('falls back to the declared order when the user has no saved layout', function () {
    $this->actingAs(NotifiableUser::create(['name' => 'U']));

    expect(app(Dashboard::class)->arranged()->map(fn ($w) => $w->key())->all())->toBe(['a', 'b', 'c']);
});

it('appends widgets added since the layout was saved', function () {
    $user = NotifiableUser::create(['name' => 'U']);
    DashboardLayout::create(['user_id' => $user->id, 'layout' => ['order' => ['b'], 'hidden' => []]]);

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

it('shows the Customize button for an authenticated user', function () {
    $this->actingAs(NotifiableUser::create(['name' => 'U']));

    expect(\Illuminate\Support\Facades\Blade::render('<x-admin-core::dashboard />'))
        ->toContain('data-ac-customize')
        ->toContain('data-ac-layout-url');
});
