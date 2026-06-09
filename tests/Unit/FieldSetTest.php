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

it('builds an enum select with an in rule', function () {
    $f = fs('status:enum:draft|published');
    expect($f->storeRules())->toContain("'in:draft,published'");
    expect($f->formFields())->toContain('<select');
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
