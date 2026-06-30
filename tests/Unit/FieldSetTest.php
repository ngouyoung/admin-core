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

it('parses decimal precision/scale (decimal:p|s) and casts to that scale, defaulting to 10,2', function () {
    $f = fs('price:decimal:12|4');
    expect($f->migrationColumns())->toContain("\$table->decimal('price', 12, 4)");
    expect($f->casts())->toContain("'decimal:4'");

    // Omitted precision/scale → the safe default; a trailing modifier still parses.
    expect(fs('amount:decimal')->migrationColumns())->toContain("\$table->decimal('amount', 10, 2)");
    expect(fs('amount:decimal')->casts())->toContain("'decimal:2'");
    expect(fs('amount:decimal:12|4?')->migrationColumns())->toContain("\$table->decimal('amount', 12, 4)->nullable()");
});

it('accepts both H:i and H:i:s for a time field (so an exported TIME round-trips on import)', function () {
    // A TIME column exports as H:i:s; the form posts H:i. The rule must accept both.
    expect(fs('start:time')->storeRules())->toContain("'date_format:H:i,H:i:s'");
});

it('rejects a comma/parenthesised enum instead of silently emitting columns like "c)"', function () {
    // The outer comma-split shatters `status:enum(a,b,c)` into `status:enum(a`, `b`, `c)` — without the
    // guard these became real columns (one literally named "c)"). The right syntax is pipe-separated.
    expect(fn () => fs('name:string, status:enum(a,b,c)'))
        ->toThrow(InvalidArgumentException::class, "unknown field type 'enum(a'");
});

it('rejects an invalid field-name identifier', function () {
    expect(fn () => fs('2bad:string'))
        ->toThrow(InvalidArgumentException::class, "invalid field name '2bad'");
});

it('rejects an unknown field type', function () {
    expect(fn () => fs('price:munny'))
        ->toThrow(InvalidArgumentException::class, "unknown field type 'munny'");
});

it('accepts the canonical pipe-separated enum syntax', function () {
    expect(fn () => fs('status:enum:draft|published|archived'))->not->toThrow(InvalidArgumentException::class);
});

it('rejects numeric enum values that would generate an invalid PHP case name', function () {
    // `enum:1|2|3` used to emit `case 1 = '1';` — a fatal parse error shipped as a "success".
    expect(fn () => fs('priority:enum:1|2|3'))
        ->toThrow(InvalidArgumentException::class, "enum value '1'");
});

it('rejects enum values that collide on the same StudlyCase case name', function () {
    expect(fn () => fs('state:enum:in-progress|in_progress'))
        ->toThrow(InvalidArgumentException::class, "both map to the");
});

it('rejects an enum with no values', function () {
    expect(fn () => fs('state:enum:'))
        ->toThrow(InvalidArgumentException::class, 'has no values');
});

it('builds a nullable decimal with a precision-capping rule', function () {
    $f = fs('price:decimal?');
    expect($f->migrationColumns())->toContain("\$table->decimal('price', 10, 2)->nullable();");
    // numeric + a DecimalPrecision rule sized to the column so an over-long value can't be truncated.
    expect($f->storeRules())->toContain("'price' => ['nullable', 'numeric', new \\Ngos\\AdminCore\\Rules\\DecimalPrecision(10, 2)]");
    expect(fs('price:decimal:12|4')->storeRules())->toContain('new \\Ngos\\AdminCore\\Rules\\DecimalPrecision(12, 4)');
});

it('builds an enum select backed by a generated enum class', function () {
    $f = fs('status:enum:draft|published')->setClass('Product');
    // Validation + form both reference the backed enum — the single source of truth.
    expect($f->storeRules())->toContain('Rule::enum(\App\Enums\ProductStatus::class)');
    expect($f->formFields())->toContain('<x-admin-core::select')
        ->toContain('\App\Enums\ProductStatus::cases()');
    // The DB column stays a plain string (adding a case never needs a migration).
    expect($f->migrationColumns())->toContain("\$table->string('status');");
    expect($f->enumDefinitions())->toBe([
        ['class' => 'ProductStatus', 'cases' => ['Draft' => 'draft', 'Published' => 'published']],
    ]);
});

it('enhances every select with select2 — enum included, not just foreign/m2m', function () {
    // The enum <select> carries .admin-core-select and an enum-only form still emits the
    // select2 init, so all dropdowns (enum / foreign / many-to-many) look and behave the same.
    $f = fs('status:enum:draft|published')->setClass('Product');
    expect($f->formFields())->toContain('<x-admin-core::select'); // the select component is select2-enhanced by default
    expect($f->formScripts())
        ->toContain(".admin-core-select')")
        ->toContain('.select2(');

    // A form with no select at all emits no select2 script.
    expect(fs('name:string')->formScripts())->toBe('');
});

it('renders a boolean as a checkbox with a hidden 0 fallback (so it can be unchecked on edit)', function () {
    $form = fs('active:boolean')->formFields();

    // A boolean field is emitted as the checkbox component (which renders the hidden-0 + box itself —
    // see the component render test in ComponentsTest for the hidden-before-checkbox ordering).
    expect($form)->toContain('<x-admin-core::checkbox name="active"')
        ->toContain(':checked="old(\'active\', $object?->active)"');
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

it('renders date/datetime via the date-input component (Air Datepicker), passing the raw value through', function () {
    $form = fs('born_on:date?, start_at:datetime?')->formFields();

    // The date-input component owns the .js-datepicker wiring + value formatting; the generator just
    // hands it the raw old()/model value (the component formats a Carbon, echoes a string as-is).
    expect($form)
        ->toContain("<x-admin-core::date-input name=\"born_on\" label=\"Born On\" :value=\"old('born_on', \$object?->born_on)\"")
        ->toContain("<x-admin-core::date-input name=\"start_at\" label=\"Start At\" mode=\"datetime\" :value=\"old('start_at', \$object?->start_at)\"")
        ->not->toContain('type="datetime-local"');
});

it('labels enum values with Str::headline (multi-word reads cleanly) across form, list and show', function () {
    $f = fs('state:enum:draft|in_progress')->setClass('Order');

    // Form select, list editColumn and show row all render the human label (in_progress -> "In Progress"),
    // not ucfirst("in_progress") = "In_progress" or the raw value — while data-status stays the raw value.
    expect($f->formFields())->toContain('Str::headline($case->value)')->not->toContain('ucfirst(');
    expect($f->getDataColumns())->toContain('Str::headline($row->state->value)')
        ->toContain("data-status=\"' . e(\$row->state->value)"); // CSS hook stays raw
    // The show view defers the pill to the reusable component (it does the headline + data-status).
    expect($f->showRows())->toContain('<x-admin-core::status :value="$object->state" />');
});

it('builds a foreign key with belongsTo + exists + eager load', function () {
    $f = fs('category_id:foreign');
    expect($f->migrationColumns())->toContain("foreignId('category_id')");
    expect($f->relations())->toContain('belongsTo(\App\Models\Category::class)');
    expect($f->storeRules())->toContain("'exists:categories,id'");
    expect($f->eager())->toContain("'category'");
});

it('a generated file field carries a mimes allowlist (no executable/markup uploads)', function () {
    // Bare 'file' accepts ANY extension; an explicit allowlist keeps php/phtml/svg/html off the public disk.
    expect(fs('manual:file')->storeRules())
        ->toContain("'file'")
        ->toContain('mimes:pdf,doc,docx,xls,xlsx,csv,txt,zip');
});

it('localizes a foreign relation display so a translatable related name never breaks', function () {
    $f = fs('category_id:foreign');

    // list column, form <select> options, and show row all run the value through ac_localize()
    expect($f->getDataColumns())->toContain('ac_localize($row->category?->name)');
    expect($f->formFields())
        ->toContain('source="categories"')                   // searchable + paginated remote select (no eager-load)
        ->toContain('ac_localize($object->category->name)');  // preselected option label, localized
    expect($f->showRows())->toContain('ac_localize($object->category?->name)');

    // and the helper resolves a translatable array to the locale string, passing plain strings through
    expect(ac_localize(['en' => 'Drinks', 'km' => 'x']))->toBe('Drinks');
    expect(ac_localize('Plain'))->toBe('Plain');
    expect(ac_localize(null))->toBe('');
});

it('points a foreign key at an explicit target table (self-reference / tree)', function () {
    // parent_id:foreign:categories on the categories table itself = a self-referencing tree.
    $f = fs('parent_id:foreign:categories', 'categories');

    // The FK targets the explicit table, never the inferred "parents".
    expect($f->migrationColumns())
        ->toContain("foreignId('parent_id')")
        ->toContain("->constrained('categories')")
        ->toContain('->nullable()')        // a self-ref FK must be nullable: the root row has no parent…
        ->toContain('->nullOnDelete()');   // …and the factory seeds null, so the migration must allow it
    // Relation + validation resolve to Category, not a non-existent Parent model.
    expect($f->relations())->toContain('belongsTo(\App\Models\Category::class)');
    expect($f->storeRules())->toContain("'exists:categories,id'");
    // A self-referencing factory must NOT recurse — it leaves the FK null.
    expect($f->factoryDefinition())->toContain("'parent_id' => null,");
});

it('points a foreign key at an explicit target for a non-conventional column name', function () {
    // author_id would infer "authors"; foreign:users points it at users instead.
    $f = fs('author_id:foreign:users', 'posts');

    expect($f->migrationColumns())->toContain("->constrained('users')");
    expect($f->relations())->toContain('belongsTo(\App\Models\User::class)');
    expect($f->storeRules())->toContain("'exists:users,id'");
    // Not a self-reference here, so the factory still builds a related row.
    expect($f->factoryDefinition())->toContain('\App\Models\User::factory()');
});

it('builds a translatable field (JSON + array cast + translatable-input + per-locale rules)', function () {
    config(['admin-core.translation.locales' => ['en' => 'English', 'km' => 'Khmer'], 'admin-core.translation.default' => 'en']);
    $f = fs('name:translatable', 'products');

    expect($f->migrationColumns())->toContain("\$table->json('name')");
    expect($f->casts())->toContain("'name' => 'array'");
    // Renders the multi-locale widget (auto-translate), not a plain text input.
    expect($f->formFields())->toContain('<x-admin-core::translatable-input name="name"');
    // Per-locale validation: default locale required, the rest optional (AutoTranslate fills them).
    expect($f->storeRules())
        ->toContain("'name' => ['required', 'array']")
        ->toContain("'name.en' => ['required', 'string', 'max:255']")
        ->toContain("'name.km' => ['nullable', 'string', 'max:255']");
    // Factory builds a per-locale array (no scalar / recursion).
    expect($f->factoryDefinition())->toContain("'en' =>")->toContain("'km' =>");
    // List + show render the active locale via ac_localize (same helper as FK display), never the raw array.
    expect($f->getDataColumns())->toContain('ac_localize($row->name)');
    expect($f->showRows())->toContain('ac_localize($object->name)');
});

it('derives a slug from a translatable name using the default locale (never Str::slug an array)', function () {
    config(['admin-core.translation.locales' => ['en' => 'English', 'km' => 'Khmer'], 'admin-core.translation.default' => 'en']);
    $f = fs('name:translatable, slug:slug', 'products');
    expect($f->bootBody())->toContain("\$model->name['en']");
});

it('builds a richtext field (text column + CKEditor editor component + stripped list + raw show)', function () {
    $f = fs('body:richtext', 'posts');
    expect($f->migrationColumns())->toContain("\$table->text('body')");
    expect($f->formFields())->toContain('<x-admin-core::editor name="body"');
    expect($f->storeRules())->toContain("'body' => ['required', 'string']");
    expect($f->factoryDefinition())->toContain('fake()->paragraph()');
    // List shows a plain-text preview; the show view renders the stored HTML.
    expect($f->getDataColumns())->toContain('strip_tags');
    expect($f->showRows())->toContain('{!! $object->body !!}');
    // Stored HTML is sanitized on save (it's echoed raw on the show page).
    expect($f->prepareBody(false))->toContain('Html::clean');
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

it('excludes trashed rows from the unique rule on a soft-deletes resource (a deleted value can be reused)', function () {
    $f = fs('slug:string^')->setSoftDeletes(true);
    // Store has no Rule import slot, so it uses the fully-qualified name + withoutTrashed.
    expect($f->storeRules())
        ->toContain('\Illuminate\Validation\Rule::unique')
        ->toContain('->withoutTrashed()')
        ->not->toContain("'unique:products,slug'");
    // Update keeps the imported short Rule, ignores self, and excludes trashed.
    expect($f->updateRules())
        ->toContain("Rule::unique('products', 'slug')->ignore")
        ->toContain('->withoutTrashed()');
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
    expect($f->serviceBody())->toContain("Media::store(\$data['photo'], 'products')");
    // Explicit mime allowlist — the bare `image` rule would also accept a script-carrying SVG.
    expect($f->storeRules())->toContain("'image'")->toContain("'mimes:jpg,jpeg,png,webp,gif'")->not->toContain('svg');

    // No soft deletes: delete() is permanent, so the file is removed there.
    expect($f->serviceBody())
        ->toContain('public function delete(')
        ->toContain('Media::delete($model->photo)')
        ->not->toContain('function forceDelete(');
});

it('deletes a file only on force-delete for a soft-deletable resource (keeps it for restore)', function () {
    $f = fs('photo:image?')->setSoftDeletes(true);

    // The file must survive a soft delete (so restore works) and be removed only on permanent delete.
    expect($f->serviceBody())
        ->toContain('public function forceDelete(')
        ->toContain('$this->findTrashed($id)')
        ->toContain('Media::delete($model->photo)')
        ->not->toContain('public function delete('); // soft delete() keeps the file
});

it('handles media/gallery as HasMedia collections (trait, no column, syncMedia, picker)', function () {
    $gallery = fs('photos:gallery');
    expect($gallery->modelTraits())->toContain('HasMedia');
    expect($gallery->fillable())->not->toContain('photos');          // a relation, not a column
    expect($gallery->serviceBody())->toContain("syncMedia(\$photos, 'photos')");
    expect($gallery->formFields())->toContain('<x-admin-core::media-collection name="photos"')->toContain(':multiple="true"');

    // a single `media` field renders a non-multiple picker
    expect(fs('hero:media')->formFields())->toContain('<x-admin-core::media-collection name="hero"')->toContain(':multiple="false"');
});

it('handles belongsToMany with a pivot migration and sync', function () {
    $f = fs('tags:belongsToMany');
    expect($f->fillable())->not->toContain('tags');
    expect($f->extraSchema())->toContain("Schema::create('product_tag'");
    expect($f->relations())->toContain('belongsToMany');
    expect($f->serviceBody())->toContain('->sync(');
});

it('renders a belongsToMany field as a searchable remote multi-select (no whole-table eager-load)', function () {
    $form = fs('tags:belongsToMany')->formFields();

    // Remote + searchable + multiple, mirroring the foreign select (source resolves the related select route).
    expect($form)->toContain('<x-admin-core::select name="tags"')
        ->toContain('source="tags"')
        ->toContain('multiple');

    // Preselect only the attached pivot rows; never eager-load the whole related table into <option>s.
    expect($form)->toContain('$object->tags->mapWithKeys')
        ->toContain("old('tags', array_keys(\$tagsSelected))")
        ->not->toContain("Tag::orderBy('id')");
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

it('emits show rows as <x-admin-core::detail-row> components (not raw <tr>)', function () {
    $rows = fs('name:string')->showRows();
    expect($rows)
        ->toContain('<x-admin-core::detail-row label="Name">')
        ->toContain('</x-admin-core::detail-row>')
        ->not->toContain('<tr>');
});

it('builds a sort column when sortable', function () {
    expect(fs('name:string')->setSortable(true)->sortColumn())->toContain("\$table->integer('sort')->default(0);");
    expect(fs('name:string')->setSortable(false)->sortColumn())->toBe('');
});

// -- computed (derived, read-only accessor) ---------------------------------------------------------

it('compiles a numeric arithmetic accessor (operators, parenthesised)', function () {
    $f = fs('qty:integer, price:decimal, total:computed:qty * price');

    expect($f->accessors())
        ->toContain('protected function total(): Attribute')
        ->toContain('Attribute::get(fn () => ($this->qty * $this->price))');
    expect($f->modelUses())->toContain('use Illuminate\Database\Eloquent\Casts\Attribute;');
    expect($f->appends())->toBe("\n\n    protected \$appends = ['total'];");
});

it('respects precedence and parentheses in a numeric expression', function () {
    expect(fs('a:integer, b:integer, c:integer, t:computed:a + b * c')->accessors())
        ->toContain('fn () => ($this->a + ($this->b * $this->c))');           // * binds tighter than +
    expect(fs('a:integer, b:integer, c:integer, t:computed:(a + b) * c')->accessors())
        ->toContain('fn () => (($this->a + $this->b) * $this->c)');           // parens override
});

it('compiles a MONEY-aware expression: money x scalar uses ->multiply() and stays money', function () {
    // qty (decimal) * unit_price (money) -> a Money line total. The real ERP case that decimal couldn't do.
    $f = fs('qty:decimal, unit_price:money, line_total:computed:qty * unit_price');

    expect($f->accessors())->toContain('fn () => $this->unit_price?->multiply($this->qty))');
    // A money-typed computed is displayed formatted (like a money column), in the list and on show.
    expect($f->getDataColumns())->toContain("->addColumn('line_total', fn (\$row) => \$row->line_total?->format())");
    expect($f->showRows())->toContain('$object->line_total?->format()');
});

it('compiles money +/- money to ->add()/->subtract(), and money / scalar to ->divide()', function () {
    expect(fs('subtotal:money, tax:money, total:computed:subtotal + tax')->accessors())
        ->toContain('fn () => $this->subtotal?->add($this->tax)');
    expect(fs('gross:money, discount:money, net:computed:gross - discount')->accessors())
        ->toContain('fn () => $this->gross?->subtract($this->discount)');
    expect(fs('total:money, parts:integer, each:computed:total / parts')->accessors())
        ->toContain('fn () => $this->total?->divide($this->parts)');
});

it('rejects nonsensical money arithmetic', function () {
    expect(fn () => fs('a:money, b:money, t:computed:a * b'))                 // money x money
        ->toThrow(InvalidArgumentException::class, "two money amounts can't be multiplied");
    expect(fn () => fs('a:money, t:computed:a + 5'))                         // money + number
        ->toThrow(InvalidArgumentException::class, "money and a plain number can't be added");
    expect(fn () => fs('a:integer, b:money, t:computed:a / b'))              // divide by money
        ->toThrow(InvalidArgumentException::class, "can't be divided by a money amount");
});

it('scaffolds a VALID-PHP TODO stub accessor for a bare computed field', function () {
    $f = fs('first_name:string, last_name:string, full_name:computed');

    expect($f->accessors())
        ->toContain('protected function fullName(): Attribute')
        ->toContain('TODO: derive full_name')
        ->toContain('fn () => null /*')   // a block comment — valid inside the arrow fn
        ->not->toContain('null;');         // NOT a statement + // comment (that breaks php -l)
});

it('compiles a literal-0 computed expression as numeric 0 (not a falsy-coerced stub)', function () {
    // `?: null` used to turn "0" into a (broken) stub; a literal 0 must compile to a real numeric body.
    $f = fs('total:computed:0');

    expect($f->accessors())->toContain('Attribute::get(fn () => 0)')
        ->not->toContain('TODO'); // it's an expression, not a stub
});

it('keeps a computed field out of the schema, fillable, rules and the form', function () {
    $f = fs('qty:integer, total:computed:qty');

    expect($f->migrationColumns())->not->toContain('total');     // not a column
    expect($f->fillable())->toBe("'qty'");                        // not mass-assignable
    expect($f->storeRules())->not->toContain("'total'");         // never validated (read-only)
    expect($f->formFields())->not->toContain('name="total"');    // not in the form
});

it('shows a numeric computed field in the list (addColumn, non-orderable) and on the show page', function () {
    $f = fs('qty:integer, total:computed:qty');

    expect($f->getDataColumns())->toContain("->addColumn('total', fn (\$row) => \$row->total)");
    expect($f->columnsConfig())->toContain("['data' => 'total', 'orderable' => false, 'searchable' => false]");
    expect($f->showRows())->toContain('$object->total');
});

it('rejects a computed expression containing a character outside the grammar (no arbitrary PHP)', function () {
    expect(fn () => fs('qty:integer, total:computed:qty % 2'))
        ->toThrow(InvalidArgumentException::class, "unexpected character '%'");
    // A function call is rejected — `abs` isn't a declared field.
    expect(fn () => fs('qty:integer, total:computed:abs(qty)'))
        ->toThrow(InvalidArgumentException::class, "references 'abs'");
});

it('rejects malformed arithmetic that would generate broken PHP', function () {
    // '//' would become a PHP line comment — it must be rejected, not emitted.
    expect(fn () => fs('qty:integer, total:computed:qty // 2'))
        ->toThrow(InvalidArgumentException::class, 'not a valid arithmetic formula');
    expect(fn () => fs('qty:integer, total:computed:qty *'))           // trailing operator
        ->toThrow(InvalidArgumentException::class, 'ends on an operator');
    expect(fn () => fs('qty:integer, total:computed:(qty * 2'))        // unbalanced paren
        ->toThrow(InvalidArgumentException::class, 'parentheses are unbalanced');
    expect(fn () => fs('qty:integer, price:integer, total:computed:qty price')) // two adjacent operands
        ->toThrow(InvalidArgumentException::class, 'unexpected trailing input');
});

it('rejects a computed field name that cannot map to an accessor (digit right after underscore)', function () {
    // Str::camel('line_2_total') = line2Total, which Str::snake's back to line2_total != line_2_total, so the
    // $appends serialisation would call a method that doesn't exist. Fail at generation, not at runtime.
    expect(fn () => fs('qty:integer, line_2_total:computed:qty*2'))
        ->toThrow(InvalidArgumentException::class, "can't map to an accessor");
    // The same guard applies to a bare stub (it also gets an accessor + appends).
    expect(fn () => fs('item_3:computed'))
        ->toThrow(InvalidArgumentException::class, "can't map to an accessor");
    // Ordinary names round-trip fine.
    expect(fn () => fs('qty:integer, grand_total:computed:qty*2'))->not->toThrow(InvalidArgumentException::class);
});

it('rejects a computed expression that references a string/enum or unknown field', function () {
    // Only numeric + money fields can appear in an expression; a string column can't.
    expect(fn () => fs('label:string, total:computed:label*2'))
        ->toThrow(InvalidArgumentException::class, "references 'label'");
    // an unknown identifier is a typo, not silent garbage.
    expect(fn () => fs('qty:integer, total:computed:qty*nope'))
        ->toThrow(InvalidArgumentException::class, "references 'nope'");
});

// -- rollup (document total = sum of a child relation) -----------------------------------------------

it('compiles a rollup into a money-aware child-sum accessor', function () {
    $f = fs('lines:hasMany:invoice_lines, total:rollup:lines.line_total');

    expect($f->accessors())
        ->toContain('protected function total(): Attribute')
        ->toContain("Attribute::get(fn () => \\Ngos\\AdminCore\\Support\\Rollup::sum(\$this->lines, 'line_total'))");
    expect($f->modelUses())->toContain('use Illuminate\Database\Eloquent\Casts\Attribute;');
    expect($f->appends())->toBe("\n\n    protected \$appends = ['total'];");
});

it('eager-loads the rolled-up relation in the list (no N+1) and shows it read-only, non-orderable', function () {
    $f = fs('lines:hasMany:invoice_lines, total:rollup:lines.line_total');

    expect($f->eager())->toContain("'lines'");                       // eager-loaded for the list sum
    expect($f->getDataColumns())->toContain("->addColumn('total', fn (\$row) => (string) \$row->total)");
    expect($f->columnsConfig())->toContain("['data' => 'total', 'orderable' => false, 'searchable' => false]");
    expect($f->showRows())->toContain('$object->total');
});

it('keeps a rollup out of the schema, fillable, rules and the form', function () {
    $f = fs('lines:hasMany:invoice_lines, total:rollup:lines.line_total');

    expect($f->migrationColumns())->not->toContain("'total'");
    expect($f->fillable())->not->toContain("'total'");
    expect($f->storeRules())->not->toContain("'total'");
    expect($f->formFields())->not->toContain('name="total"');
});

it('resolves a rollup whose relation is written as the snake field name', function () {
    // `order_lines:hasMany` -> relation method orderLines; the rollup may reference either spelling.
    $f = fs('order_lines:hasMany:invoice_lines, total:rollup:order_lines.line_total');

    expect($f->accessors())->toContain('Rollup::sum($this->orderLines, ');
});

it('rejects a rollup that does not reference a declared hasMany relation', function () {
    expect(fn () => fs('total:rollup:lines.line_total'))                       // no `lines` relation
        ->toThrow(InvalidArgumentException::class, "isn't a hasMany");
    expect(fn () => fs('lines:hasMany:invoice_lines, total:rollup:lines'))     // missing .attribute
        ->toThrow(InvalidArgumentException::class, 'relation.attribute');
});
