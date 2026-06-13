<?php

use Illuminate\Support\Facades\File;

/*
 * `admin-core:field` adds fields to an EXISTING resource — migration + model +
 * requests + views + factory — and is idempotent (existing fields are skipped).
 * Reuses the gizmo scaffolding helpers/cleanup from GeneratorTest.
 */

beforeEach(fn () => cleanupGizmo());
afterEach(fn () => cleanupGizmo());

function makeGizmo(): void
{
    test()->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, price:decimal',
        '--migration' => true,
    ])->assertSuccessful();
}

it('errors when the resource does not exist', function () {
    $this->artisan('admin-core:field', ['name' => 'Nope', 'fields' => 'sku:string'])
        ->assertFailed();
});

it('adds new fields across migration, model, requests, views and factory', function () {
    makeGizmo();

    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'status:enum:draft|paid, note:text?'])
        ->assertSuccessful();

    // A dedicated add migration with both columns + a dropColumn down().
    $add = collect(glob(database_path('migrations/*_add_status_note_to_gizmos_table.php')))->first();
    expect($add)->not->toBeNull();
    expect(File::get($add))
        ->toContain("Schema::table('gizmos'")
        ->toContain("\$table->string('status')")
        ->toContain("\$table->text('note')->nullable()")
        ->toContain("dropColumn(['status', 'note'])");

    // Model: fillable extended + the enum cast added.
    expect(File::get(app_path('Models/Gizmo.php')))
        ->toContain("'price', 'status', 'note'")
        ->toContain("'status' => \App\Enums\GizmoStatus::class");

    // Requests: Rule::enum + nullable text, inserted into rules().
    expect(File::get(app_path('Http/Requests/Gizmo/StoreGizmoRequest.php')))
        ->toContain('Rule::enum(\App\Enums\GizmoStatus::class)')
        ->toContain("'note' => ['nullable', 'string']");

    // Views: <th> before Actions, columns before the actions column.
    expect(File::get(resource_path('views/backend/pages/gizmos/partials/thead.blade.php')))
        ->toContain('<th>Status</th>')->toContain('<th>Note</th>');
    expect(File::get(resource_path('views/backend/pages/gizmos/partials/scripts.blade.php')))
        ->toContain("{data: 'status', name: 'status'}")
        ->toContain("{data: 'note', name: 'note'}");

    // Form + factory + the backed enum class.
    expect(File::get(resource_path('views/backend/pages/gizmos/partials/form.blade.php')))->toContain('name="status"');
    expect(File::get(database_path('factories/GizmoFactory.php')))->toContain('GizmoStatus::cases()');
    expect(File::exists(app_path('Enums/GizmoStatus.php')))->toBeTrue();
});

it('skips fields that already exist (idempotent), adding only the new one', function () {
    makeGizmo();
    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'sku:string'])->assertSuccessful();

    // Re-run with the existing sku + a new colour: sku skipped, colour added.
    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'sku:string, colour:string'])
        ->expectsOutputToContain('already exists — skipped: sku')
        ->assertSuccessful();

    // sku appears once in $fillable (not duplicated); colour was added.
    $model = File::get(app_path('Models/Gizmo.php'));
    expect(substr_count($model, "'sku'"))->toBe(1);
    expect($model)->toContain("'sku', 'colour'");

    // Re-running with only the existing field is a no-op.
    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'sku:string'])
        ->expectsOutputToContain('Nothing to add')
        ->assertSuccessful();
});
