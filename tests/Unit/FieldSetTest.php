<?php

use Ngos\AdminCore\Console\FieldSet;

function fs(string $dsl, string $table = 'products'): FieldSet
{
    return (new FieldSet($dsl))->setTable($table);
}

it('defaults to a single name string field', function () {
    $f = fs('');
    expect($f->fillable())->toBe("'name'");
    expect($f->migrationColumns())->toContain("\$table->string('name');");
    expect($f->storeRules())->toContain("'name' => ['required', 'string', 'max:255']");
});

it('builds a nullable decimal', function () {
    $f = fs('price:decimal?');
    expect($f->migrationColumns())->toContain("\$table->decimal('price', 10, 2)->nullable();");
    expect($f->storeRules())->toContain("'price' => ['nullable', 'numeric']");
});

it('builds an enum select backed by a generated enum class', function () {
    $f = fs('status:enum:draft|published')->setClass('Product');
    // Validation + form both reference the backed enum — the single source of truth.
    expect($f->storeRules())->toContain('Rule::enum(\App\Enums\ProductStatus::class)');
    expect($f->formFields())->toContain('<select')
        ->toContain('\App\Enums\ProductStatus::cases()');
    // The DB column stays a plain string (adding a case never needs a migration).
    expect($f->migrationColumns())->toContain("\$table->string('status');");
    expect($f->enumDefinitions())->toBe([
        ['class' => 'ProductStatus', 'cases' => ['Draft' => 'draft', 'Published' => 'published']],
    ]);
});

it('renders a boolean as a checkbox with a hidden 0 fallback (so it can be unchecked on edit)', function () {
    $form = fs('active:boolean')->formFields();

    // Hidden 0 must come *before* the checkbox so the checkbox's 1 wins when checked,
    // and the field is always submitted (an unchecked checkbox sends nothing on its own).
    expect($form)->toContain('<input type="hidden" name="active" value="0">')
        ->toContain('type="checkbox" name="active"');
    expect(strpos($form, 'value="0"'))->toBeLessThan(strpos($form, 'type="checkbox"'));
});

it('renders boolean and date columns in the list (not raw true/false or ISO strings)', function () {
    $f = fs('active:boolean, published_at:datetime?, born_on:date?');

    expect($f->getDataColumns())
        ->toContain("editColumn('active'") // Yes/No badge, not raw true/false
        ->toContain('Yes')->toContain('No')
        ->toContain("editColumn('published_at'")->toContain("format('Y-m-d H:i')")
        ->toContain("editColumn('born_on'")->toContain("format('Y-m-d')");

    // The boolean cell emits HTML, so it must be whitelisted as a raw column.
    expect($f->rawColumns())->toContain("'active'");
});

it('builds a foreign key with belongsTo + exists + eager load', function () {
    $f = fs('category_id:foreign');
    expect($f->migrationColumns())->toContain("foreignId('category_id')");
    expect($f->relations())->toContain('belongsTo(\App\Models\Category::class)');
    expect($f->storeRules())->toContain("'exists:categories,id'");
    expect($f->eager())->toContain("'category'");
});

it('handles a unique field with the Rule import on update', function () {
    $f = fs('slug:string^');
    expect($f->migrationColumns())->toContain('->unique();');
    expect($f->storeRules())->toContain("'unique:products,slug'");
    expect($f->updateRules())->toContain("Rule::unique('products', 'slug')->ignore");
    expect($f->updateUses())->toContain('use Illuminate\Validation\Rule;');
});

it('uses the hybrid key strategy (bigint id + public uuid, bigint FKs)', function () {
    $f = fs('name:string')->setUuid(true);
    // bigint PK + a unique public uuid column (not a uuid primary key).
    expect($f->primaryKey())->toContain('$table->id();')->toContain("\$table->uuid('uuid')->unique();");
    expect($f->modelTraits())->toContain('HasPublicUuid');
    expect($f->modelUses())->toContain('Ngos\AdminCore\Concerns\HasPublicUuid');
    // Foreign keys stay lean bigint, never uuid.
    expect(fs('category_id:foreign')->setUuid(true)->migrationColumns())
        ->toContain('foreignId')->not->toContain('foreignUuid');
});

it('supports soft deletes', function () {
    $f = fs('name:string')->setSoftDeletes(true);
    expect($f->modelTraits())->toContain('SoftDeletes');
    expect($f->softDeletesColumn())->toContain('softDeletes()');
});

it('handles image uploads with service-side storage', function () {
    $f = fs('photo:image?');
    expect($f->migrationColumns())->toContain("\$table->string('photo')->nullable();");
    expect($f->enctype())->toContain('multipart/form-data');
    expect($f->serviceBody())->toContain("store('products', 'public')");
    expect($f->storeRules())->toContain("'image'");
});

it('handles belongsToMany with a pivot migration and sync', function () {
    $f = fs('tags:belongsToMany');
    expect($f->fillable())->not->toContain('tags');
    expect($f->extraSchema())->toContain("Schema::create('product_tag'");
    expect($f->relations())->toContain('belongsToMany');
    expect($f->serviceBody())->toContain('->sync(');
});

it('builds a field-aware factory definition', function () {
    $f = fs('name:string, price:decimal?');
    expect($f->factoryDefinition())->toContain("'name' => fake()->name()");
    expect($f->factoryDefinition())->toContain("'price' => fake()->randomFloat");
});

it('adds the LogsActivity trait when audited', function () {
    $f = fs('name:string')->setAudit(true);
    expect($f->modelTraits())->toContain('LogsActivity');
    expect($f->modelUses())->toContain('use Ngos\AdminCore\Concerns\LogsActivity;');
});

it('adds a sort column when sortable', function () {
    $f = fs('name:string')->setSortable(true);
    expect($f->isSortable())->toBeTrue();
    expect($f->sortColumn())->toContain("\$table->integer('sort')->default(0);");
});

it('builds read-only show rows', function () {
    $f = fs('name:string, category_id:foreign');
    expect($f->showRows())->toContain('$object->name');
    expect($f->showRows())->toContain('$object->category?->name');
});

it('builds a sort column when sortable', function () {
    expect(fs('name:string')->setSortable(true)->sortColumn())->toContain("\$table->integer('sort')->default(0);");
    expect(fs('name:string')->setSortable(false)->sortColumn())->toBe('');
});
