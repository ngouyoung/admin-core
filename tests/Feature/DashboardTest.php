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
