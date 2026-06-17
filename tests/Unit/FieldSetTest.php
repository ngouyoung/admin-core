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

it('accepts both H:i and H:i:s for a time field (so an exported TIME round-trips on import)', function () {
    // A TIME column exports as H:i:s; the form posts H:i. The rule must accept both.
    expect(fs('start:time')->storeRules())->toContain("'date_format:H:i,H:i:s'");
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

it('renders date/datetime as Air Datepicker text inputs, value-formatted to the shape it + the date rule parse', function () {
    $form = fs('born_on:date?, start_at:datetime?')->formFields();

    // Themed picker (theme.js attaches to .js-datepicker, mode from data-adp), not the native input.
    // A raw Carbon ("Y-m-d H:i:s") wouldn't round-trip; format to Y-m-d / Y-m-d H:i.
    expect($form)
        ->toContain('class="form-control js-datepicker @error(\'born_on\') is-invalid @enderror" autocomplete="off" data-adp="date"')
        ->toContain("old('born_on', \$object?->born_on?->format('Y-m-d'))")
        ->toContain('data-adp="datetime"')
        ->toContain("old('start_at', \$object?->start_at?->format('Y-m-d H:i'))")
        ->not->toContain('type="datetime-local"');
});

it('labels enum values with Str::headline (multi-word reads cleanly) across form, list and show', function () {
    $f = fs('state:enum:draft|in_progress')->setClass('Order');

    // Form select, list editColumn and show row all render the human label (in_progress -> "In Progress"),
    // not ucfirst("in_progress") = "In_progress" or the raw value — while data-status stays the raw value.
    expect($f->formFields())->toContain('Str::headline($case->value)')->not->toContain('ucfirst(');
    expect($f->getDataColumns())->toContain('Str::headline($row->state->value)')
        ->toContain("data-status=\"' . e(\$row->state->value)"); // CSS hook stays raw
    expect($f->showRows())->toContain('Str::headline($object->state->value)')
        ->toContain('data-status="{{ $object->state->value }}"');
});

it('builds a foreign key with belongsTo + exists + eager load', function () {
    $f = fs('category_id:foreign');
    expect($f->migrationColumns())->toContain("foreignId('category_id')");
    expect($f->relations())->toContain('belongsTo(\App\Models\Category::class)');
    expect($f->storeRules())->toContain("'exists:categories,id'");
    expect($f->eager())->toContain("'category'");
});

it('makes a belongsTo list column searchable and sortable by the related name', function () {
    $f = fs('category_id:foreign'); // table = products

    // JS column carries a name and is no longer searchable:false (so the global search + sort reach it).
    expect($f->columnsJs())->toContain("{data: 'category', name: 'category'}");

    // getData wires the search (whereHas on the related name) and sort (correlated subquery).
    expect($f->getDataColumns())
        ->toContain("->filterColumn('category', fn (\$q, \$keyword) => \$q->whereHas('category'")
        ->toContain("->where('name', 'like', \"%{\$keyword}%\")")
        ->toContain("->orderColumn('category'")
        ->toContain("\\App\\Models\\Category::select('name')->whereColumn('categories.id', 'products.category_id')");
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

    // No soft deletes: delete() is permanent, so the file is removed there.
    expect($f->serviceBody())
        ->toContain('public function delete(')
        ->toContain("Storage::disk('public')->delete(\$model->photo)")
        ->not->toContain('function forceDelete(');
});

it('deletes a file only on force-delete for a soft-deletable resource (keeps it for restore)', function () {
    $f = fs('photo:image?')->setSoftDeletes(true);

    // The file must survive a soft delete (so restore works) and be removed only on permanent delete.
    expect($f->serviceBody())
        ->toContain('public function forceDelete(')
        ->toContain('$this->findTrashed($id)')
        ->toContain("Storage::disk('public')->delete(\$model->photo)")
        ->not->toContain('public function delete('); // soft delete() keeps the file
});

it('handles belongsToMany with a pivot migration and sync', function () {
    $f = fs('tags:belongsToMany');
    expect($f->fillable())->not->toContain('tags');
    expect($f->extraSchema())->toContain("Schema::create('product_tag'");
    expect($f->relations())->toContain('belongsToMany');
    expect($f->serviceBody())->toContain('->sync(');
});

it('includes belongsTo + belongsToMany in the export relations, and lists export-picker fields', function () {
    $f = fs('name:string, category_id:foreign, tags:belongsToMany');

    // Both relation kinds get a readable export column (belongsTo name + m2m joined names).
    expect($f->exportRelations())->toContain("'category'")->toContain("'tags'");

    // The field picker offers id + the value columns + relation names + timestamps; never the m2m as a column.
    $picker = $f->exportFields();
    expect($picker)->toHaveKeys(['id', 'name', 'category_id', 'category', 'tags', 'created_at', 'updated_at']);
});

it('keeps password columns out of the export field picker', function () {
    expect(fs('name:string, secret:password')->exportFields())
        ->toHaveKey('name')->not->toHaveKey('secret');
});

it('makes a belongsToMany list column searchable by the related name (not sortable)', function () {
    $f = fs('tags:belongsToMany');

    // Searchable (name + no searchable:false) but not orderable (multi-value relation).
    expect($f->columnsJs())->toContain("{data: 'tags', name: 'tags', orderable: false}");

    // Search wires a whereHas on the related name; no orderColumn (sorting a m2m is ambiguous).
    expect($f->getDataColumns())
        ->toContain("->filterColumn('tags', fn (\$q, \$keyword) => \$q->whereHas('tags'")
        ->not->toContain("->orderColumn('tags'");
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
