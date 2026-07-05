<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Ngos\AdminCore\Dashboard\Dashboard;
use Ngos\AdminCore\Dashboard\DashboardContext;
use Ngos\AdminCore\Dashboard\Widgets\StatWidget;

it('resolves inline config-array widgets and renders their data', function () {
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'title' => 'Widgets', 'icon' => 'bi-box', 'value' => fn () => 4200],
    ]]);

    $widgets = app(Dashboard::class)->widgets();
    expect($widgets)->toHaveCount(1);

    $data = $widgets->first()->data(DashboardContext::fromPreset('30d'));
    expect($data['value'])->toBe('4,200')->and($data['title'])->toBe('Widgets'); // numeric values are formatted
});

it('resolves a runtime-registered (closure) widget, appended to the config ones (config-cache safe path)', function () {
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'key' => 'cfg', 'title' => 'FromConfig', 'value' => 1],
    ]]);
    // A closure widget registered in a service provider's boot() — the cache-safe home for closures.
    Dashboard::flushRegistered();
    Dashboard::register(['type' => 'stat', 'key' => 'reg', 'title' => 'Registered', 'value' => fn () => 7]);

    $widgets = app(Dashboard::class)->widgets();
    expect($widgets->map(fn ($w) => $w->key())->all())->toContain('cfg', 'reg') // both config + registered appear
        ->and($widgets->firstWhere(fn ($w) => $w->key() === 'reg')->data(DashboardContext::fromPreset('30d'))['value'])->toBe('7');

    Dashboard::flushRegistered(); // don't leak into other tests
});

it('does not leak runtime registrations across an app reboot (registry is container-scoped, not static)', function () {
    config(['admin-core.dashboard.widgets' => []]);
    Dashboard::register(['type' => 'stat', 'key' => 'boot1', 'title' => 'FromBoot1', 'value' => 1]);
    expect(app(Dashboard::class)->widgets()->map(fn ($w) => $w->key())->all())->toBe(['boot1']);

    // A new app boot in the SAME PHP process (a worker, or the consumer's next test) — a static registry
    // would keep 'boot1'; a container-scoped one starts empty.
    $this->refreshApplication();
    config(['admin-core.dashboard.widgets' => []]);

    expect(app(Dashboard::class)->widgets()->map(fn ($w) => $w->key())->all())->toBe([]); // 'boot1' did NOT carry over

    Dashboard::register(['type' => 'stat', 'key' => 'boot2', 'title' => 'FromBoot2', 'value' => 2]);
    expect(app(Dashboard::class)->widgets()->map(fn ($w) => $w->key())->all())->toBe(['boot2']); // only this boot's
});

it('resolves a class-string widget through the container', function () {
    config(['admin-core.dashboard.widgets' => [DashboardTestStat::class]]);

    $widget = app(Dashboard::class)->widgets()->first();
    expect($widget)->toBeInstanceOf(StatWidget::class)
        ->and($widget->data(DashboardContext::fromPreset('30d'))['value'])->toBe('99');
});

it('computes a trend arrow from the current vs previous value', function () {
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'title' => 'Sales', 'value' => fn () => 120, 'previous' => fn () => 100],
    ]]);

    $trend = app(Dashboard::class)->widgets()->first()->data(DashboardContext::fromPreset('30d'))['trend'];
    expect($trend['dir'])->toBe('up')->and($trend['pct'])->toBe(20.0);
});

it('hides a widget the viewer lacks permission for', function () {
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'title' => 'Public', 'value' => fn () => 1],
        ['type' => 'stat', 'title' => 'Secret', 'value' => fn () => 2, 'can' => 'view-secret'],
    ]]);

    // No authenticated user → the permissioned widget is filtered out, the public one stays.
    $titles = app(Dashboard::class)->widgets()->map(fn ($w) => $w->title())->all();
    expect($titles)->toBe(['Public']);
});

it('does not share a cached widget payload between two portal (non-default-guard) users', function () {
    // payload() used auth()->id() (DEFAULT guard only), so every merchant-guard user collapsed to one
    // 'guest' cache bucket — user #10's per-user widget value was served to user #20. Key by [id, guard].
    cache()->flush();
    config()->set('admin-core.permission.guards', ['merchant' => []]);
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'title' => 'Wallet', 'cache' => 60, 'value' => fn () => (int) (auth()->guard('merchant')->user()->name)],
    ]]);

    // In-memory users with distinct ids — the cache key reads only the guard id, no DB row needed.
    $m10 = (new \Ngos\AdminCore\Tests\Fixtures\NotifiableUser(['name' => '500']))->forceFill(['id' => 10]);
    $m20 = (new \Ngos\AdminCore\Tests\Fixtures\NotifiableUser(['name' => '900']))->forceFill(['id' => 20]);

    $dashboard = app(Dashboard::class);
    $widget = $dashboard->widgets()->first();
    $context = DashboardContext::fromPreset('30d');

    auth()->guard('merchant')->setUser($m10);
    expect($dashboard->payload($widget, $context)['value'])->toEqual(500);

    // A DIFFERENT merchant-guard user within the TTL must NOT get #10's cached 500.
    auth()->guard('merchant')->setUser($m20);
    expect($dashboard->payload($widget, $context)['value'])->toEqual(900); // own data, not the shared bucket
});

it('caches a widget payload for its TTL so the data closure runs once', function () {
    cache()->flush();
    $calls = 0;
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'title' => 'Heavy', 'cache' => 60, 'value' => function () use (&$calls) {
            $calls++;

            return 5;
        }],
    ]]);

    $dashboard = app(Dashboard::class);
    $widget = $dashboard->widgets()->first();
    $context = DashboardContext::fromPreset('30d');

    $dashboard->payload($widget, $context);
    $dashboard->payload($widget, $context); // second call served from cache

    expect($calls)->toBe(1);
});

it('falls back to the preset range on a malformed ?from/?to (no 500)', function () {
    // Request::date('from') runs Carbon::parse on the raw value — 'garbage' would throw. The context must
    // degrade to the default preset, not bubble a 500.
    $ctx = DashboardContext::fromRequest(new Illuminate\Http\Request(['from' => 'garbage', 'to' => 'not-a-date']));

    expect($ctx->range)->toBe(config('admin-core.dashboard.default_range', '30d'))
        ->and($ctx->range)->not->toBe('custom');
});

it('builds a date range + previous comparison window from a preset', function () {
    $now = CarbonImmutable::parse('2026-06-15 12:00:00');

    $week = DashboardContext::fromPreset('7d', $now);
    expect($week->from->toDateString())->toBe('2026-06-08')
        ->and($week->to->toDateString())->toBe('2026-06-15')
        ->and($week->previousFrom->toDateString())->toBe('2026-06-01')   // the 7 days before the window
        ->and($week->previousTo->toDateString())->toBe('2026-06-08');

    $all = DashboardContext::fromPreset('all', $now);
    expect($all->from)->toBeNull()->and($all->hasRange())->toBeFalse(); // "all time" applies no date scope
});

it('renders the dashboard component with stat, chart and list widgets', function () {
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'title' => 'Orders', 'icon' => 'bi-bag', 'value' => fn () => 7, 'link' => '/admin/orders'],
        ['type' => 'chart', 'title' => 'Trend', 'chart' => fn () => ['type' => 'line', 'series' => [['name' => 's', 'data' => [1, 2, 3]]], 'categories' => ['a', 'b', 'c']]],
        ['type' => 'list', 'title' => 'Recent', 'rows' => fn () => [['label' => 'Row A', 'link' => '/x', 'badge' => 'new']]],
    ]]);

    $html = Blade::render('<x-admin-core::dashboard />');

    expect($html)
        ->toContain('Orders')->toContain('ac-stat')->toContain('/admin/orders') // stat + drill-down link
        ->toContain('Trend')->toContain('data-ac-chart')                         // chart container
        ->toContain('Recent')->toContain('Row A')->toContain('new')             // list rows
        ->toContain('range=7d');                                                 // date-range toolbar
});

it('shows an empty hint when no widgets are configured', function () {
    config(['admin-core.dashboard.widgets' => []]);

    expect(Blade::render('<x-admin-core::dashboard />'))->toContain('No dashboard widgets yet');
});

it('renders a single widget through the lazy-load endpoint', function () {
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'key' => 'orders', 'title' => 'Orders', 'value' => fn () => 12],
    ]]);
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Route::adminCoreDashboard());

    $this->get('/admin/dashboard/widget/orders')->assertOk()->assertSee('Orders')->assertSee('12');
});

it('404s the endpoint for an unknown or hidden widget key', function () {
    config(['admin-core.dashboard.widgets' => []]);
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Route::adminCoreDashboard());

    $this->get('/admin/dashboard/widget/nope')->assertNotFound();
});

it('renders a lazy widget as a skeleton + endpoint url (not inline)', function () {
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'key' => 'heavy', 'title' => 'Heavy', 'lazy' => true, 'value' => fn () => 1],
    ]]);
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Route::adminCoreDashboard());
    Route::getRoutes()->refreshNameLookups(); // tests add routes after boot; refresh so Route::has() sees it

    expect(Blade::render('<x-admin-core::dashboard />'))
        ->toContain('data-ac-widget-lazy')
        ->toContain('dashboard/widget/heavy')
        ->not->toContain('Heavy'); // the value loads via the endpoint, so the title isn't rendered inline
});

it('carries a custom from/to window on the lazy widget endpoint url', function () {
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'key' => 'heavy', 'title' => 'Heavy', 'lazy' => true, 'value' => fn () => 1],
    ]]);
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Route::adminCoreDashboard());
    Route::getRoutes()->refreshNameLookups(); // tests add routes after boot; refresh so Route::has() sees it

    // The endpoint rebuilds the context from ITS OWN request — a bare ?range=custom would fall back to the
    // default preset, so a custom-range dashboard would quietly lazy-load 30-day data. The widget URL must
    // carry the actual window.
    $this->app->instance('request', Illuminate\Http\Request::create('/admin/dashboard', 'GET', ['from' => '2026-01-05', 'to' => '2026-01-20']));

    expect(Blade::render('<x-admin-core::dashboard />'))
        ->toContain('from=2026-01-05')
        ->toContain('to=2026-01-20');
});

it('does not share a cached payload between two different custom date windows', function () {
    cache()->flush();
    $calls = 0;
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'title' => 'Heavy', 'cache' => 60, 'value' => function () use (&$calls) {
            $calls++;

            return 1;
        }],
    ]]);
    $dashboard = app(Dashboard::class);
    $widget = $dashboard->widgets()->first();

    $jan = DashboardContext::fromRequest(new Illuminate\Http\Request(['from' => '2026-01-01', 'to' => '2026-01-07']));
    $jun = DashboardContext::fromRequest(new Illuminate\Http\Request(['from' => '2026-06-01', 'to' => '2026-06-30']));

    $dashboard->payload($widget, $jan);
    $dashboard->payload($widget, $jun); // different window must miss January's cache entry

    expect($calls)->toBe(2);
});

it('does not share a cached payload between two different param sets on the same range', function () {
    cache()->flush();
    $calls = 0;
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'title' => 'Heavy', 'cache' => 60, 'value' => function () use (&$calls) {
            $calls++;

            return 1;
        }],
    ]]);
    $dashboard = app(Dashboard::class);
    $widget = $dashboard->widgets()->first();

    // Params scope a widget's data just like the date window — a ?status= filter must not be served
    // the other status's cached numbers within the TTL.
    $dashboard->payload($widget, DashboardContext::fromPreset('30d', null, ['status' => 'paid']));
    $dashboard->payload($widget, DashboardContext::fromPreset('30d', null, ['status' => 'void']));
    expect($calls)->toBe(2);

    // …while the same params (whatever the key order) do share the entry, and range/from/to in params
    // don't fragment the preset key (they're already part of the signature).
    $dashboard->payload($widget, DashboardContext::fromPreset('30d', null, ['status' => 'paid']));
    expect($calls)->toBe(2)
        ->and(DashboardContext::fromPreset('30d', null, ['b' => 2, 'a' => 1])->cacheSignature())
            ->toBe(DashboardContext::fromPreset('30d', null, ['a' => 1, 'b' => 2])->cacheSignature())
        ->and(DashboardContext::fromPreset('30d', null, ['range' => '30d'])->cacheSignature())
            ->toBe(DashboardContext::fromPreset('30d')->cacheSignature());
});

it('dedupes widgets that resolve to the same key, keeping the first', function () {
    config(['admin-core.dashboard.widgets' => [
        ['type' => 'stat', 'title' => 'Sales', 'value' => fn () => 1], // key "sales"
        ['type' => 'stat', 'title' => 'Sales', 'value' => fn () => 2], // duplicate key "sales"
        ['type' => 'stat', 'title' => 'Other', 'value' => fn () => 3],
    ]]);

    $widgets = app(Dashboard::class)->widgets();
    expect($widgets)->toHaveCount(2) // the duplicate is dropped, not silently lost later in arranged()
        ->and($widgets->first()->data(DashboardContext::fromPreset('30d'))['value'])->toBe('1');
});

it('keeps the previous comparison window disjoint from the current one', function () {
    $now = CarbonImmutable::parse('2026-06-15 12:00:00');

    foreach (['7d', '30d'] as $preset) {
        $ctx = DashboardContext::fromPreset($preset, $now);
        expect($ctx->previousTo->lessThan($ctx->from))->toBeTrue(); // no shared boundary instant → no double-count
    }
});

/** A minimal class widget for the container-resolution test. */
class DashboardTestStat extends StatWidget
{
    public function title(): string
    {
        return 'Container';
    }

    public function value(DashboardContext $context): float|int|string
    {
        return 99;
    }
}
