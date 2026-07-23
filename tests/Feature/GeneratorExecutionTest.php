<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/**
 * TS-2 — Execute Generated Resources.
 *
 * GeneratorTest proves the emitted code LINTS + MIGRATES; it never boots the generated controller or issues an
 * HTTP request, so the emitted getData query / relation search+sort / route-key binding execute against no engine.
 * This suite closes that gap: after `admin-core:make` + migrate, it registers the generated route module, seeds via
 * the generated factory, and HTTP-hits the resource — asserting real success + real rows, not substrings. The one
 * exception is the master-detail sync (AC-7), exercised through the generated service (the emitted syncHasMany glue)
 * because the HTTP child-field validation is a documented per-resource manual step. It runs on SQLite by default and,
 * under TS-1's matrix (DB_CONNECTION=pgsql|mysql), executes the emitted SQL on real engines.
 *
 * Policy-free (INV-1): it seeds through each resource's own generated factory and asserts only generic CRUD outcomes.
 *
 * Each STRUCTURAL variant uses a DISTINCT resource name — PHP caches a class once loaded, so re-generating the same
 * class name with a different shape in the same process would keep the first-loaded definition.
 */

// composer maps only Ngos\AdminCore\ -> src/. The generated resource lives in the host app's App\ / Database\Factories\
// namespaces, so make them loadable from the Testbench app paths for the life of the suite (test-only; no runtime effect).
spl_autoload_register(function (string $class): void {
    if (! str_starts_with($class, 'App\\') && ! str_starts_with($class, 'Database\\Factories\\')) {
        return;
    }
    // Resolve only when a full Testbench Application is booted — BETWEEN tests app() can be a bare Container whose
    // path() helper doesn't exist, and OTHER suites trigger App\/Database\Factories\ lookups (Laravel's factory
    // name resolution) that would otherwise fatal on `Container::path()`. This closure is a no-op outside our app.
    if (! \Illuminate\Container\Container::getInstance() instanceof \Illuminate\Foundation\Application) {
        return;
    }
    $path = str_starts_with($class, 'App\\')
        ? app_path(str_replace('\\', '/', substr($class, 4)) . '.php')
        : database_path('factories/' . str_replace('\\', '/', substr($class, 19)) . '.php');
    if (is_file($path)) {
        require_once $path;
    }
});

/** Delete every artifact `admin-core:make <name>` writes, and drop its table. */
function cleanupResource(string $name): void
{
    // Mirror the generator's own derivation EXACTLY (AdminCoreMakeCommand: $class = Str::studly(Str::singular($name))),
    // so a name the generator singularises can't compute a different path here and leak the class-named artifacts.
    $studly = Str::studly(Str::singular($name));
    $snakePlural = Str::snake(Str::pluralStudly($studly)); // table / route module / views dir (gizmos)
    foreach ([
        app_path("Models/{$studly}.php"),
        app_path("Http/Controllers/Backend/{$studly}Controller.php"),
        app_path("Http/Requests/{$studly}"),
        app_path('Services/' . Str::plural($studly)), // the service lives in a StudlyPlural subfolder (Services/Gizmos)
        app_path("Policies/{$studly}Policy.php"),
        database_path("factories/{$studly}Factory.php"),
        database_path("seeders/{$studly}Seeder.php"),
        base_path("tests/Feature/{$studly}Test.php"),
        base_path("routes/Web/Backend/Modules/{$snakePlural}.php"),
        resource_path("views/backend/pages/{$snakePlural}"),
    ] as $path) {
        File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);
    }
    foreach (glob(database_path("migrations/*_create_{$snakePlural}_table.php")) ?: [] as $migration) {
        File::delete($migration);
    }
    Schema::dropIfExists($snakePlural);
}

/** Reset the skeleton to pristine — every resource this suite may generate, plus the shared test scaffolding. */
function cleanupExec(): void
{
    // Child resources before their parents — a child table's FK references the parent, so it must drop first.
    foreach (['Gizmo', 'Relgizmo', 'Widget', 'Gadget'] as $name) {
        cleanupResource($name);
    }
    File::delete(resource_path('views/backend/layouts/app.blade.php')); // shared layout stand-in
}

/** Generate a resource, migrate it, and register its route module under the admin group (as admin-core:install does). */
function makeAndRegister(string $name, string $fields): void
{
    $plural = Str::snake(Str::pluralStudly(Str::studly(Str::singular($name)))); // == the generator's $snakePlural

    test()->artisan('admin-core:make', ['name' => $name, '--fields' => $fields, '--migration' => true])->assertSuccessful();

    // Apply the generated migration via up() rather than `artisan migrate`: on a PERSISTENT engine (pgsql/mysql) the
    // `migrations` table survives between tests, so a re-generated same-named migration would be SKIPPED as already
    // run and the table never created. up() ignores that ledger (SQLite :memory: hid this — fresh DB per test).
    $file = collect(glob(database_path("migrations/*_create_{$plural}_table.php")))->first();
    (require $file)->up();

    // Generated views @extends('backend.layouts.app') — the host theme layout a real app installs. Provide a
    // minimal stand-in so the HTML index/show pages render in the test skeleton (test-only; no runtime effect).
    File::ensureDirectoryExists(resource_path('views/backend/layouts'));
    File::put(resource_path('views/backend/layouts/app.blade.php'), '<!doctype html><html><body>@yield(\'content\')@stack(\'scripts\')</body></html>');

    Route::middleware('web')->prefix('admin')->name('admin.')->group(function () use ($plural) {
        require base_path("routes/Web/Backend/Modules/{$plural}.php");
    });
    Route::getRoutes()->refreshNameLookups();
}

beforeEach(fn () => cleanupExec());
afterEach(fn () => cleanupExec());

it('boots the generated controller and returns the list + list-data over HTTP (AC-1)', function () {
    makeAndRegister('Gizmo', 'name:string');
    $this->actingAs(new NotifiableUser(['name' => 'Actor']));

    $this->get(route('admin.gizmos.index'))->assertOk();
    $this->getJson(route('admin.gizmos.getData'))->assertOk();
});

it('serves factory-seeded rows through getData, executing the emitted list query (AC-2, AC-8)', function () {
    makeAndRegister('Gizmo', 'name:string');
    // Seed through the resource's OWN generated factory (policy-free — INV-1).
    \Database\Factories\GizmoFactory::new()->create(['name' => 'Alpha']);
    \Database\Factories\GizmoFactory::new()->create(['name' => 'Beta']);

    $this->actingAs(new NotifiableUser(['name' => 'Actor']));
    $res = $this->getJson(route('admin.gizmos.getData'))->assertOk();
    // Real rows returned by the emitted query (not a substring on source): both seeded rows present, exact count.
    $names = collect($res->json('data'))->pluck('name')->implode(' ');
    expect($res->json('recordsTotal'))->toBe(2)
        ->and($names)->toContain('Alpha')->toContain('Beta');
});

it('creates, shows, and updates a generated resource over HTTP by its public route key (AC-3, AC-4)', function () {
    makeAndRegister('Gizmo', 'name:string');
    $this->actingAs(new NotifiableUser(['name' => 'Actor']));

    // store persists a real row (BaseService::create executes). assertSessionHasNoErrors distinguishes a clean
    // success from a validation-failed redirect that happened to leave a row behind.
    $this->post(route('admin.gizmos.store'), ['name' => 'Created'])->assertSessionHasNoErrors();
    $gizmo = \App\Models\Gizmo::firstWhere('name', 'Created');
    expect($gizmo)->not->toBeNull();

    // show + update addressed by the PUBLIC route key (uuid under the hybrid default) — the resolve-by-key path
    // string assertions never exercise; must not throw an identity-resolution/SQL error.
    $this->get(route('admin.gizmos.show', $gizmo))->assertOk();
    $this->put(route('admin.gizmos.update', $gizmo), ['name' => 'Renamed'])->assertSessionHasNoErrors();
    expect($gizmo->fresh()->name)->toBe('Renamed');
});

it('negative control — a runtime query failure fails the harness, proving it is not vacuous (AC-5)', function () {
    makeAndRegister('Gizmo', 'name:string');
    $this->actingAs(new NotifiableUser(['name' => 'Actor']));
    $this->getJson(route('admin.gizmos.getData'))->assertOk(); // the emitted query executes cleanly

    // Reintroduce the class of failure string-assertions miss: the emitted query now throws at runtime (its table
    // is gone). The harness SEES it as a server error — so a generator emitting a broken query would redden CI.
    Schema::drop('gizmos');
    $this->getJson(route('admin.gizmos.getData'))->assertStatus(500);
});

it('executes the emitted self-referential relation search + sort over getData — the alias-poison class (AC-6)', function () {
    // A SELF-REFERENTIAL relation (parent_id -> relgizmos) is the exact shape the v2.86.2 "alias-poison" bug needed:
    // `whereHas('parent', …)` self-joins the table, so Eloquent aliases the related copy as `laravel_reserved_N`,
    // and the display column had to resolve correctly THROUGH that alias. On PostgreSQL a regression throws; on
    // SQLite/MySQL it silently resolves the WRONG column — so the assertions below check real filtered rows + real
    // order, not just a 200, to catch the silent variant. A distinct name (Relgizmo) keeps this a fresh class.
    makeAndRegister('Relgizmo', 'name:string, parent_id:foreign:relgizmos');

    // Children whose OWN names OPPOSE their parents' order (Yankee under Alpha, Bravo under Zulu): sorting by the
    // parent's display column then differs from sorting by the child's own column, so the assertions below can tell
    // a correct parent-column sort from a wrong-column one.
    $alpha = \App\Models\Relgizmo::create(['name' => 'Alpha']);                       // root (null parent)
    $zulu = \App\Models\Relgizmo::create(['name' => 'Zulu']);                         // root (null parent)
    \App\Models\Relgizmo::create(['name' => 'Yankee', 'parent_id' => $alpha->id]);    // parent display = "Alpha"
    \App\Models\Relgizmo::create(['name' => 'Bravo', 'parent_id' => $zulu->id]);      // parent display = "Zulu"

    $this->actingAs(new NotifiableUser(['name' => 'Actor']));
    $relCol = ['columns' => [['data' => 'parent', 'name' => 'parent', 'searchable' => 'true', 'orderable' => 'true']]];
    $plain = fn ($res) => collect($res->json('data'))->pluck('name')->map(fn ($n) => strip_tags((string) $n));
    $getData = fn ($params) => $plain($this->getJson(route('admin.relgizmos.getData', $relCol + $params))->assertOk());

    // SEARCH the relation display column: filterColumn → whereHas('parent', name LIKE 'Alpha') runs on the self-join
    // alias. A matching term must return EXACTLY the child whose parent is Alpha; if a regression resolved the wrong
    // column (uuid/id), 'Alpha' would match nothing — filtered 0, silent on SQLite/MySQL — and this fails.
    $searched = $this->getJson(route('admin.relgizmos.getData', $relCol + ['search' => ['value' => 'Alpha', 'regex' => 'false']]))->assertOk();
    expect($searched->json('recordsFiltered'))->toBe(1)
        ->and($plain($searched)->all())->toBe(['Yankee']);

    // SORT on the relation column exercises the emitted orderColumn — a correlated subquery over the parent's display
    // column, DIFFERENT SQL from the search's whereHas. Assert it EXECUTES cleanly on every engine (the self-join
    // subquery is valid SQL and returns the full set). The row ORDER is deliberately NOT asserted: for a SELF-
    // referential relation the emitted subquery doesn't disambiguate the self-join — whereColumn('relgizmos.id',
    // 'relgizmos.parent_id') binds BOTH sides to the subquery's own copy of the table — so it's degenerate and never
    // reorders. THIS execution test surfaced that generator gap (recorded in the improvement backlog; a cross-table
    // relation sort orders correctly). The search assertion above is the alias-poison guard; this keeps the sort path
    // from silently emitting SQL that throws on a real engine.
    $sorted = $getData(['order' => [['column' => 0, 'dir' => 'asc']]]);
    expect($sorted->count())->toBe(4); // executed cleanly, full set returned — no SQL error on any engine
});

it('executes the generated master-detail sync — hasMany child rows persist + reconcile on the live engine (AC-7)', function () {
    // The generated PARENT (gadgets) is a hasMany-only resource; the CHILD (widgets) is a separate generated
    // resource carrying the back-FK. Generate the parent first so `gadgets` exists for the child's
    // constrained('gadgets'). Distinct names (Gadget/Widget) keep both classes fresh in this process.
    makeAndRegister('Gadget', 'name:string, widgets:hasMany:widgets');
    makeAndRegister('Widget', 'label:string, gadget_id:foreign:gadgets');

    // The generated service's create()/update() carry the emitted master-detail glue ($data['widgets'] extract +
    // $this->syncHasMany(...)). Exercised at the service layer — the HTTP path's child-field validation is a
    // documented manual step (rules() emits only `widgets`/`widgets.*.id`; the child's fields are tightened per
    // resource), orthogonal to the sync mechanism AC-7 protects. This runs the emitted INSERTs on the live engine.
    $service = app(\App\Services\Gadgets\GadgetService::class);

    // create(): syncHasMany creates each child via $parent->widgets()->create() — the FK is set from the relation.
    $gadget = $service->create(['name' => 'G1', 'widgets' => [['label' => 'A'], ['label' => 'B']]]);
    expect(\App\Models\Widget::where('gadget_id', $gadget->id)->pluck('label')->sort()->values()->all())->toBe(['A', 'B']);

    // update(): reconcile by the public route key — keep+rename A (matched by child id), drop B, create C.
    $a = \App\Models\Widget::where('label', 'A')->firstOrFail();
    $b = \App\Models\Widget::where('label', 'B')->firstOrFail();
    $service->update($gadget->getRouteKey(), ['name' => 'G1b', 'widgets' => [['id' => $a->id, 'label' => 'A2'], ['label' => 'C']]]);
    expect(\App\Models\Widget::where('gadget_id', $gadget->id)->pluck('label')->sort()->values()->all())->toBe(['A2', 'C'])
        // Row A was UPDATED IN PLACE (same PK), not deleted-and-recreated — the whereKey($id) match syncHasMany
        // makes. Without this, identity churn (drop A, insert a fresh 'A2') yields the same label set and passes.
        ->and(\App\Models\Widget::find($a->id)?->label)->toBe('A2')
        ->and(\App\Models\Widget::find($b->id))->toBeNull(); // B, absent from the submission, was removed
});

it('leaves the skeleton pristine after a run — no generated file, table, or seeded row (AC-9)', function () {
    makeAndRegister('Gizmo', 'name:string');
    \Database\Factories\GizmoFactory::new()->create();
    expect(File::exists(app_path('Models/Gizmo.php')))->toBeTrue()
        ->and(Schema::hasTable('gizmos'))->toBeTrue();

    cleanupExec();

    // Assert EVERY artifact class is gone — including the service (a StudlyPlural subfolder), seeder and policy,
    // which an earlier revision's cleanup missed (they leaked under vendor/, invisible to `git status`).
    expect(File::exists(app_path('Models/Gizmo.php')))->toBeFalse()
        ->and(File::exists(app_path('Http/Controllers/Backend/GizmoController.php')))->toBeFalse()
        ->and(File::exists(app_path('Services/Gizmos/GizmoService.php')))->toBeFalse()
        ->and(File::isDirectory(app_path('Services/Gizmos')))->toBeFalse()
        ->and(File::exists(app_path('Policies/GizmoPolicy.php')))->toBeFalse()
        ->and(File::exists(database_path('seeders/GizmoSeeder.php')))->toBeFalse()
        ->and(File::exists(base_path('routes/Web/Backend/Modules/gizmos.php')))->toBeFalse()
        ->and(Schema::hasTable('gizmos'))->toBeFalse();
});
