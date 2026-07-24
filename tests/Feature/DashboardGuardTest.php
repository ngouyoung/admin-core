<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Dashboard\Dashboard;
use Ngos\AdminCore\Models\DashboardLayout;
use Ngos\AdminCore\Support\ActorResolver;
use Ngos\AdminCore\Support\Sidebar;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/*
 * WP-B11b — dashboard widget authorization follows the panel guard (implements ADR-0009).
 *
 * The dashboard's per-widget permission gate rode the portal-first ActorResolver (AR-1), while every OTHER
 * on-page authorization element (Sidebar, Search, WebController) resolves against the panel's own guard. Under a
 * dual session that split the on-page identity. Per ADR-0009 — authorization = the panel guard; attribution =
 * the resolved actor (portal-first) — the dashboard now authorizes against a host-declared guard when supplied,
 * defaulting to the unchanged portal-first behavior. These tests permute the dual-session states the ACs name.
 */

beforeEach(function () {
    Schema::dropIfExists('dashboard_layouts'); // defensive for persistent engines (TS-1)
    Schema::dropIfExists('users');
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

    config(['admin-core.permission.enabled' => true]);
    config()->set('admin-core.permission.guards', ['merchant' => []]); // 'merchant' is a configured portal guard
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);
    config()->set('auth.defaults.guard', 'web');

    // Per-user permissions: A holds see-a (not see-b); B holds see-b (not see-a). $user->can() routes each
    // widget's permission through the Gate for THAT user instance, so the acting identity decides visibility.
    Gate::define('see-a', fn ($user) => ($user->name ?? null) === 'A');
    Gate::define('see-b', fn ($user) => ($user->name ?? null) === 'B');

    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'key' => 'wa', 'title' => 'OnlyA', 'value' => fn () => 1, 'can' => 'see-a'],
        ['type' => 'stat', 'key' => 'wb', 'title' => 'OnlyB', 'value' => fn () => 2, 'can' => 'see-b'],
        ['type' => 'stat', 'key' => 'pub', 'title' => 'Public', 'value' => fn () => 3],
    ]]);
});

afterEach(function () {
    Schema::dropIfExists('dashboard_layouts');
    Schema::dropIfExists('users');
});

/** A dual active session: default web user A AND portal (merchant) user B, both authenticated in one request. */
function dualSession(): array
{
    $a = NotifiableUser::create(['name' => 'A']);
    $b = NotifiableUser::create(['name' => 'B']);
    auth()->guard('web')->setUser($a);       // default guard = A
    auth()->guard('merchant')->setUser($b);  // portal guard = B  → BOTH now active

    return [$a, $b];
}

/** Package root — for reading shipped source/docs in the file-scan ACs. */
function dashRoot(): string
{
    return dirname(__DIR__, 2);
}

it('AC-1: the dashboard component declares an optional guard prop, documented mirroring sidebar-menu', function () {
    $src = file_get_contents(dashRoot() . '/resources/views/components/dashboard.blade.php');

    expect($src)->toContain("@props(['guard' => null])")       // optional prop, default null
        ->toContain('<x-admin-core::dashboard guard="merchant" />') // docblock usage example (mirrors sidebar-menu)
        ->toContain('ADR-0009')                                 // rationale reference
        ->toContain('->forGuard($guard)');                      // threaded into the Dashboard instance
});

it('AC-2/AC-4: with a panel guard, dashboard + sidebar authorize ONE identity; the no-prop path documents the split', function () {
    [$a, $b] = dualSession(); // web=A, merchant=B

    // Authorized on the WEB panel guard, the dashboard resolves user A → sees A's widget, hides B's.
    $webTitles = app(Dashboard::class)->forGuard('web')->widgets()->map(fn ($w) => $w->title())->all();
    expect($webTitles)->toContain('OnlyA')->toContain('Public')->not->toContain('OnlyB');

    // The SIDEBAR passed the SAME guard authorizes the SAME identity (A) — one identity governs the render.
    $menu = [
        ['label' => 'A-item', 'url' => '/a', 'can' => 'see-a'],
        ['label' => 'B-item', 'url' => '/b', 'can' => 'see-b'],
    ];
    $sidebarLabels = collect(Sidebar::items($menu, 'web'))->pluck('label')->all();
    expect($sidebarLabels)->toContain('A-item')->not->toContain('B-item'); // A governs the sidebar too

    // Symmetric: authorized on the MERCHANT panel guard, the dashboard resolves user B.
    $merchTitles = app(Dashboard::class)->forGuard('merchant')->widgets()->map(fn ($w) => $w->title())->all();
    expect($merchTitles)->toContain('OnlyB')->toContain('Public')->not->toContain('OnlyA');

    // COMPANION no-prop fixture — the PRE-B11b split this WP removes: the no-prop dashboard resolves portal-first
    // (B) while the web sidebar resolves A, so two identities decide one page. Pinned so a regression is caught.
    $noProp = app(Dashboard::class)->widgets()->map(fn ($w) => $w->title())->all();
    expect($noProp)->toContain('OnlyB')->not->toContain('OnlyA') // dashboard = portal-first B …
        ->and($sidebarLabels)->toContain('A-item');             // … while sidebar(web) = A → the divergence
});

it('AC-3: with NO guard prop, widget visibility is the portal-first ActorResolver identity (unchanged)', function () {
    [$a, $b] = dualSession();

    // No prop → actingUser() = ActorResolver::user() = portal-first = B (merchant consulted before web).
    expect(ActorResolver::user()?->getAuthIdentifier())->toBe($b->getAuthIdentifier());

    $titles = app(Dashboard::class)->widgets()->map(fn ($w) => $w->title())->all();
    expect($titles)->toContain('OnlyB')->toContain('Public')->not->toContain('OnlyA'); // == portal-first identity
});

it('AC-5: a declared guard with no user hides permissioned widgets even when ANOTHER guard is live (fail-closed, panel-guard honored)', function () {
    // web=A is LIVE, but the DECLARED panel guard 'merchant' has no user. Fail-closed must still hide A's
    // widget — proving the gate honors the *declared* (empty) guard and does NOT fall through to web=A or to
    // the portal-first resolver (which, with only web live, would resolve A and wrongly show OnlyA). This
    // discriminates the panel-guard path from portal-first — it would fail if forGuard() were ignored.
    $a = NotifiableUser::create(['name' => 'A']);
    auth()->guard('web')->setUser($a);

    $titles = app(Dashboard::class)->forGuard('merchant')->widgets()->map(fn ($w) => $w->title())->all();
    expect($titles)->toBe(['Public']); // OnlyA hidden despite A being live on web → the declared guard governs
});

it('AC-1/AC-2: rendering the component WITH a guard prop authorizes the panel-guard set end-to-end (arranged path)', function () {
    dualSession(); // web=A, merchant=B both live

    // The Blade component renders arranged() on the forGuard()-configured instance. Proves the prop drives
    // authorization through the REAL component (not just the AC-1 source scan) and exercises arranged() — the
    // path the page actually renders — WITH a declared guard.
    $merchantHtml = \Illuminate\Support\Facades\Blade::render('<x-admin-core::dashboard guard="merchant" />');
    expect($merchantHtml)->toContain('OnlyB')->toContain('Public')->not->toContain('OnlyA'); // panel guard = B

    // The discriminating case: on the WEB panel, guard="web" authorizes A (portal-first would have shown B).
    $webHtml = \Illuminate\Support\Facades\Blade::render('<x-admin-core::dashboard guard="web" />');
    expect($webHtml)->toContain('OnlyA')->toContain('Public')->not->toContain('OnlyB'); // panel guard = A
});

it('AC-6: the lazy endpoint authorizes against the SERVER-SIDE route-default guard, not portal-first', function () {
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Route::adminCoreDashboard('web'));
    Route::getRoutes()->refreshNameLookups();
    [$a, $b] = dualSession();
    $this->actingAs($a, 'web');
    $this->actingAs($b, 'merchant');

    // Panel guard = web = A: A's widget renders; B's is 404 — even though the portal user B could see it. The
    // guard comes from the route default (host-declared), so the caller cannot escalate to the portal identity.
    $this->get('/admin/dashboard/widget/wa')->assertOk()->assertSee('OnlyA');
    $this->get('/admin/dashboard/widget/wb')->assertNotFound();
});

it('AC-6: with NO macro guard argument, the endpoint authorizes portal-first (the unchanged default path)', function () {
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Route::adminCoreDashboard());
    Route::getRoutes()->refreshNameLookups();
    [$a, $b] = dualSession();
    $this->actingAs($a, 'web');
    $this->actingAs($b, 'merchant');

    // No route-default guard → portal-first = B → B's widget renders; A-only is 404 (portal-first ≠ web).
    $this->get('/admin/dashboard/widget/wb')->assertOk()->assertSee('OnlyB');
    $this->get('/admin/dashboard/widget/wa')->assertNotFound();
});

it('AC-6/AC-7: saveLayout filters to the authz-guard set, but persists under the portal-first actor', function () {
    [$a, $b] = dualSession(); // web=A, merchant=B

    // Authorize on the WEB panel guard (A). 'wb' is NOT visible to A, so it is filtered from the saved order —
    // yet the row keys on the PORTAL-FIRST actor (merchant B), unchanged from v3.0.3 (ADR-0009 attribution).
    app(Dashboard::class)->forGuard('web')->saveLayout(['wa', 'wb', 'pub'], []);

    $row = DashboardLayout::first();
    expect($row->guard)->toBe('merchant')                                 // attribution: portal-first actor …
        ->and((int) $row->user_id)->toBe((int) $b->getAuthIdentifier())   // … = merchant user B, NOT web A
        ->and($row->layout['order'])->toBe(['wa', 'pub']);                // authorization: web=A's set (wb dropped)
});

it('AC-6/AC-7: the saveLayout ENDPOINT authorizes + filters against the route-default guard (write-side HTTP twin)', function () {
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Route::adminCoreDashboard('web'));
    Route::getRoutes()->refreshNameLookups();
    [$a, $b] = dualSession();
    $this->actingAs($a, 'web');
    $this->actingAs($b, 'merchant');

    // Panel guard = web = A (server-side route default). Post an order that includes 'wb' — a widget only the
    // portal user B could see. The endpoint must (a) authorize with the route-default guard, filtering 'wb'
    // out (A can't see it), and (b) persist under the PORTAL-FIRST actor (merchant B) — ADR-0009. Exercises
    // the saveLayout controller seam end-to-end (the write-side twin of the widget() HTTP test).
    $this->postJson('/admin/dashboard/layout', ['order' => ['wa', 'wb', 'pub'], 'hidden' => []])
        ->assertOk()->assertJson(['ok' => true]);

    $row = DashboardLayout::first();
    expect($row->guard)->toBe('merchant')                                 // attribution: portal-first actor B …
        ->and((int) $row->user_id)->toBe((int) $b->getAuthIdentifier())   // … not the 'web' authz guard
        ->and($row->layout['order'])->toBe(['wa', 'pub']);                // authz = web(A)'s set; 'wb' dropped
});

it('AC-7: attribution is portal-first even with no dual session — single-panel storage is unchanged', function () {
    // A plain single (default-guard) session: authz guard supplied or not, the layout keys on the same actor.
    $u = NotifiableUser::create(['name' => 'A']);
    $this->actingAs($u); // web only — portal-first resolves this same user

    app(Dashboard::class)->forGuard('web')->saveLayout(['wa', 'pub'], []);

    $row = DashboardLayout::first();
    expect($row->guard)->toBe('web')->and((int) $row->user_id)->toBe((int) $u->id);
});

it('AC-8: mechanism-not-policy — the authz gate reads a guard NAME + the widget\'s own permission, no domain concept', function () {
    $src = file_get_contents(dashRoot() . '/src/Dashboard/Dashboard.php');

    // Positive: the resolution path uses only the guard-name primitives the siblings (Sidebar/Search) use.
    expect($src)->toContain('auth()->guard($this->guard)->user()')
        ->toContain('\Ngos\AdminCore\Support\ActorResolver::user()');
    // Structural mechanism-not-policy: the permission is DATA read off the widget, never a hardcoded domain
    // permission, and the identity is a guard NAME — the gate cannot name any invoice/course/employee/SKU.
    expect($src)->toContain('$permission = $widget->permission();')
        ->toContain('$user->can($permission)');
});

it('AC-9: the dashboard guard code cites ADR-0008 and ADR-0009 for traceability', function () {
    $src = file_get_contents(dashRoot() . '/src/Dashboard/Dashboard.php');

    expect($src)->toContain('ADR-0008')->toContain('ADR-0009');
});

it('AC-10: CHANGELOG and UPGRADING document the optional guard prop + default-unchanged guarantee', function () {
    $changelog = file_get_contents(dashRoot() . '/CHANGELOG.md');
    $upgrading = file_get_contents(dashRoot() . '/UPGRADING.md');

    expect($changelog)->toContain('WP-B11b')->toContain('guard` prop')->toContain('ADR-0009');
    expect($upgrading)->toContain('guard` prop')->toContain('portal-first');
});
