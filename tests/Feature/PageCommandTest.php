<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * admin-core:page scaffolds a standalone (non-CRUD) page — an invokable controller,
 * a Blade view (page-header + card + empty-state), and a route under
 * routes/Web/Backend/Modules — plus a sidebar menu entry when the config marker exists.
 */

function pageTargets(): array
{
    return [
        app_path('Http/Controllers/Backend/ReportsController.php'),
        app_path('Http/Controllers/Backend/SalesReportController.php'),
        resource_path('views/backend/pages/reports.blade.php'),
        resource_path('views/backend/pages/sales-report.blade.php'),
        base_path('routes/Web/Backend/Modules/reports.php'),
        base_path('routes/Web/Backend/Modules/sales-report.php'),
    ];
}

function cleanupPage(): void
{
    foreach (pageTargets() as $p) {
        File::delete($p);
    }
}

beforeEach(fn () => cleanupPage());
afterEach(fn () => cleanupPage());

it('scaffolds a controller, view and route for a standalone page', function () {
    $this->artisan('admin-core:page', ['name' => 'Reports', '--no-menu' => true])->assertSuccessful();

    // Controller: invokable, extends the host base controller, returns the view; valid PHP, no tokens.
    $controllerPath = app_path('Http/Controllers/Backend/ReportsController.php');
    expect(File::get($controllerPath))
        ->toContain('class ReportsController extends Controller')
        ->toContain('public function __invoke(Request $request): View')
        ->toContain("return view('backend.pages.reports')")
        ->not->toContain('__AC_');
    expect(Process::run('php -l ' . escapeshellarg($controllerPath))->successful())->toBeTrue();

    // View: extends the layout and composes the reusable components.
    expect(File::get(resource_path('views/backend/pages/reports.blade.php')))
        ->toContain("@extends('backend.layouts.app')")
        ->toContain('<x-admin-core::page-header title="Reports"')
        ->toContain('<x-admin-core::card>')
        ->toContain('<x-admin-core::empty-state');

    // Route: GET admin/reports → the invokable controller, gated by view-reports; valid PHP.
    $routePath = base_path('routes/Web/Backend/Modules/reports.php');
    expect(File::get($routePath))
        ->toContain('use App\Http\Controllers\Backend\ReportsController;')
        ->toContain("Route::get('reports', ReportsController::class)")
        ->toContain("->name('reports')")
        ->toContain("permission:view-reports");
    expect(Process::run('php -l ' . escapeshellarg($routePath))->successful())->toBeTrue();
});

it('scaffolds a data-driven report with --report', function () {
    $this->artisan('admin-core:page', ['name' => 'Reports', '--report' => true, '--no-menu' => true])->assertSuccessful();

    // Controller hands the view a rows collection; valid PHP, no tokens.
    $controllerPath = app_path('Http/Controllers/Backend/ReportsController.php');
    expect(File::get($controllerPath))
        ->toContain('public function __invoke(Request $request): View')
        ->toContain('$rows = collect();')
        ->toContain("return view('backend.pages.reports', compact('rows'))")
        ->not->toContain('__AC_');
    expect(Process::run('php -l ' . escapeshellarg($controllerPath))->successful())->toBeTrue();

    // View: the report table pattern — count badge, empty-state and a rows loop.
    $viewPath = resource_path('views/backend/pages/reports.blade.php');
    expect(File::get($viewPath))
        ->toContain('$rows->count()')
        ->toContain('$rows->isEmpty()')
        ->toContain('<x-admin-core::empty-state')
        ->toContain('@foreach ($rows as $row)')
        ->toContain('<table');

    // …and it compiles to valid PHP (a malformed directive would break compileString or the lint).
    $compiled = app('blade.compiler')->compileString(File::get($viewPath));
    $tmp = sys_get_temp_dir() . '/ac_report_compiled.php';
    File::put($tmp, $compiled);
    expect(Process::run('php -l ' . escapeshellarg($tmp))->successful())->toBeTrue();
    File::delete($tmp);
});

it('kebab-cases a multi-word name for the slug, route and view', function () {
    $this->artisan('admin-core:page', ['name' => 'Sales Report', '--no-menu' => true])->assertSuccessful();

    expect(File::exists(app_path('Http/Controllers/Backend/SalesReportController.php')))->toBeTrue()
        ->and(File::exists(resource_path('views/backend/pages/sales-report.blade.php')))->toBeTrue()
        ->and(File::get(base_path('routes/Web/Backend/Modules/sales-report.php')))
        ->toContain("Route::get('sales-report', SalesReportController::class)")
        ->toContain("->name('sales-report')");
});

it('omits the permission gate with --no-permission', function () {
    $this->artisan('admin-core:page', ['name' => 'Reports', '--no-menu' => true, '--no-permission' => true])
        ->assertSuccessful();

    expect(File::get(base_path('routes/Web/Backend/Modules/reports.php')))
        ->toContain("->name('reports')")
        ->not->toContain('permission:');
});

it('adds a sidebar menu entry at the config marker (idempotent)', function () {
    $config = config_path('admin-core.php');
    $original = File::exists($config) ? File::get($config) : null;
    File::ensureDirectoryExists(dirname($config));
    File::put($config, "<?php\n\nreturn [\n    'menu' => [\n        // admin-core:menu\n    ],\n];\n");

    $this->artisan('admin-core:page', ['name' => 'Reports'])->assertSuccessful();
    expect(File::get($config))
        ->toContain("'label' => 'Reports'")
        ->toContain("'route' => 'admin.reports'")
        ->toContain("'can' => 'view-reports'")
        ->toContain("'match' => 'admin/reports*'");

    // Re-running (with --force for the files) must not duplicate the menu entry.
    $this->artisan('admin-core:page', ['name' => 'Reports', '--force' => true])->assertSuccessful();
    expect(substr_count(File::get($config), "'route' => 'admin.reports'"))->toBe(1);

    $original === null ? File::delete($config) : File::put($config, $original);
});

it('refuses to overwrite an existing page without --force', function () {
    $this->artisan('admin-core:page', ['name' => 'Reports', '--no-menu' => true])->assertSuccessful();
    $this->artisan('admin-core:page', ['name' => 'Reports', '--no-menu' => true])->assertFailed();
});
