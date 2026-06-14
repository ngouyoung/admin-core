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

it('refuses when the table has no migration and does not exist (no orphan migration)', function () {
    // Resource generated WITHOUT --migration: model exists, but no table / create migration.
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string'])->assertSuccessful();

    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'sku:string'])
        ->expectsOutputToContain("Table 'gizmos' doesn't exist")
        ->assertFailed();

    // Nothing was patched and no add migration was written.
    expect(glob(database_path('migrations/*_add_sku_to_gizmos_table.php')))->toBeEmpty();
    expect(File::get(app_path('Models/Gizmo.php')))->not->toContain("'sku'");
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

    // Show (detail) view: a row per new field, above the Created/timestamps row.
    $show = File::get(resource_path('views/backend/pages/gizmos/show.blade.php'));
    expect($show)
        ->toContain('$object->note')
        ->toContain('$object->status->value')                                        // enum rendered by value
        ->and(strpos($show, '$object->note'))->toBeLessThan(strpos($show, '>Created</th>')); // before timestamps

    // Form + factory + the backed enum class.
    expect(File::get(resource_path('views/backend/pages/gizmos/partials/form.blade.php')))->toContain('name="status"');
    expect(File::get(database_path('factories/GizmoFactory.php')))->toContain('GizmoStatus::cases()');
    expect(File::exists(app_path('Enums/GizmoStatus.php')))->toBeTrue();
});

it('adds the Rule import when a unique field is added to a request that lacked it', function () {
    // A resource with no unique field — its UpdateRequest has no `use …\Rule;`.
    test()->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'title:string', '--migration' => true])
        ->assertSuccessful();
    expect(File::get(app_path('Http/Requests/Gizmo/UpdateGizmoRequest.php')))
        ->not->toContain('use Illuminate\Validation\Rule;');

    // Adding a unique field injects `Rule::unique(...)->ignore(...)` — the import must come with it,
    // or the request fatals with "Class Rule not found" on update.
    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'sku:string^'])->assertSuccessful();

    $update = File::get(app_path('Http/Requests/Gizmo/UpdateGizmoRequest.php'));
    expect($update)
        ->toContain('use Illuminate\Validation\Rule;')
        ->toContain("Rule::unique('gizmos', 'sku')");
    // The import is declared before it's used (and only once).
    expect(substr_count($update, 'use Illuminate\Validation\Rule;'))->toBe(1)
        ->and(strpos($update, 'use Illuminate\Validation\Rule;'))->toBeLessThan(strpos($update, 'Rule::unique('));
});

it('wires the booted() slug derive when adding a slug, and skips system fields', function () {
    test()->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string', '--migration' => true])
        ->assertSuccessful();

    // slug = wireable (fillable-tracked); code:sku = system (not mass-assignable) → skipped.
    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'handle:slug, code:sku'])
        ->expectsOutputToContain('needs the full generator — skipped: code')
        ->assertSuccessful();

    $model = File::get(app_path('Models/Gizmo.php'));
    expect($model)
        ->toContain('protected static function booted(): void')
        ->toContain('static::creating(function (self $model) {')
        ->toContain('$model->handle ??= \Illuminate\Support\Str::slug($model->name);')
        ->toContain("'name', 'handle'")          // slug added to fillable…
        ->not->toContain("'code'");              // …system field was not

    // Adding a second slug extends the existing booted() (one method, two derives).
    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'tag:slug'])->assertSuccessful();
    $model = File::get(app_path('Models/Gizmo.php'));
    expect(substr_count($model, 'function booted('))->toBe(1)
        ->and(substr_count($model, 'Str::slug($model->name)'))->toBe(2);
});

it('adds password columns to the model $hidden (so the hash is never serialised)', function () {
    test()->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'title:string', '--migration' => true])
        ->assertSuccessful();
    expect(File::get(app_path('Models/Gizmo.php')))->not->toContain('$hidden');

    // First password → adds the $hidden declaration.
    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'secret:password'])->assertSuccessful();
    expect(File::get(app_path('Models/Gizmo.php')))->toContain("protected \$hidden = ['secret'];");

    // Second password → extends it (not a second declaration).
    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'pin:password'])->assertSuccessful();
    $model = File::get(app_path('Models/Gizmo.php'));
    expect($model)->toContain("protected \$hidden = ['secret', 'pin'];")
        ->and(substr_count($model, 'protected $hidden'))->toBe(1);
});

it('wires prepareForValidation for json (decode) and password (drop blank on update)', function () {
    test()->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'title:string', '--migration' => true])
        ->assertSuccessful();

    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'meta:json, secret:password'])
        ->assertSuccessful();

    // Store: json decoded (so the textarea string satisfies the `array` rule); no password drop on create.
    expect(File::get(app_path('Http/Requests/Gizmo/StoreGizmoRequest.php')))
        ->toContain('protected function prepareForValidation(): void')
        ->toContain("json_decode(\$this->meta, true)")
        ->not->toContain('blank($this->secret)');

    // Update: json decoded AND a blank password is dropped so the stored hash isn't overwritten.
    expect(File::get(app_path('Http/Requests/Gizmo/UpdateGizmoRequest.php')))
        ->toContain("json_decode(\$this->meta, true)")
        ->toContain('blank($this->secret)');

    // Adding another json field extends the existing method rather than adding a second one.
    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'payload:json'])->assertSuccessful();
    $update = File::get(app_path('Http/Requests/Gizmo/UpdateGizmoRequest.php'));
    expect(substr_count($update, 'function prepareForValidation'))->toBe(1)
        ->and($update)->toContain("json_decode(\$this->payload, true)");
});

it('syncs a new field into the API channel when the resource has one', function () {
    test()->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string',
        '--api' => true,
        '--migration' => true,
    ])->assertSuccessful();

    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'tier:enum:free|pro, blurb:string'])
        ->assertSuccessful();

    // The JsonResource exposes the new fields…
    expect(File::get(app_path('Http/Resources/GizmoResource.php')))
        ->toContain("'tier' => \$this->tier")
        ->toContain("'blurb' => \$this->blurb");

    // …and the whitelists pick them up by type (blurb searchable, both sortable, tier filterable).
    expect(File::get(app_path('Http/Controllers/Api/GizmoApiController.php')))
        ->toContain("\$searchable = ['name', 'blurb']")
        ->toContain("'tier', 'blurb']")          // appended to sortable
        ->toContain("\$filterable = ['tier']");
});

it('skips relation/upload fields (they need the full generator), adding the scalar ones', function () {
    makeGizmo();

    // category_id (foreign) + avatar (image) can't be surgically wired; sku (scalar) can.
    $this->artisan('admin-core:field', ['name' => 'Gizmo', 'fields' => 'sku:string, category_id:foreign, avatar:image'])
        ->expectsOutputToContain('needs the full generator — skipped: category_id')
        ->expectsOutputToContain('needs the full generator — skipped: avatar')
        ->assertSuccessful();

    $model = File::get(app_path('Models/Gizmo.php'));
    expect($model)->toContain("'sku'")
        ->not->toContain("'category_id'")
        ->not->toContain("'avatar'");
    // No belongsTo relation was bolted on (that's the whole reason we skip it).
    expect($model)->not->toContain('belongsTo');
    expect(glob(database_path('migrations/*_add_sku_to_gizmos_table.php')))->toHaveCount(1);
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
