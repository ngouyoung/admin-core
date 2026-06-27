<?php

use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(app_path('Dashboard'));
});

it('scaffolds a stat widget class by default', function () {
    $this->artisan('admin-core:make-widget', ['name' => 'Revenue'])->assertSuccessful();

    $path = app_path('Dashboard/RevenueWidget.php');
    expect(File::exists($path))->toBeTrue();
    expect(File::get($path))
        ->toContain('class RevenueWidget extends StatWidget')
        ->toContain("return 'Revenue';")
        ->toContain('public function value(DashboardContext $context)');
});

it('scaffolds a chart widget with --type=chart', function () {
    $this->artisan('admin-core:make-widget', ['name' => 'Sales', '--type' => 'chart'])->assertSuccessful();

    expect(File::get(app_path('Dashboard/SalesWidget.php')))
        ->toContain('extends ChartWidget')
        ->toContain('public function chart(DashboardContext $context)')
        ->toContain("'type' => 'line'");
});

it('scaffolds a list widget with --type=list', function () {
    $this->artisan('admin-core:make-widget', ['name' => 'LatestOrders', '--type' => 'list'])->assertSuccessful();

    expect(File::get(app_path('Dashboard/LatestOrdersWidget.php')))
        ->toContain('extends ListWidget')
        ->toContain('public function rows(DashboardContext $context): iterable');
});

it('rejects an unknown widget type', function () {
    $this->artisan('admin-core:make-widget', ['name' => 'X', '--type' => 'bogus'])->assertFailed();
});

it('refuses to overwrite an existing widget without --force', function () {
    $this->artisan('admin-core:make-widget', ['name' => 'Dup'])->assertSuccessful();
    $this->artisan('admin-core:make-widget', ['name' => 'Dup'])->assertFailed();
    $this->artisan('admin-core:make-widget', ['name' => 'Dup', '--force' => true])->assertSuccessful();
});
