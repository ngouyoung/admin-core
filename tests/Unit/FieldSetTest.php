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

it('derives the decimal form step from the column scale (not a hardcoded 0.01)', function () {
    // A number input with step="0.01" rejects any value finer than 2 decimals in the browser — so a
    // decimal:12|3 field (scale 3) couldn't submit a legitimate 1.234. The step must match the scale.
    expect(fs('rate:decimal:12|3')->formFields())
        ->toContain('type="number" step="0.001"')
        ->not->toContain('step="0.01"');

    // A scale-0 decimal steps by whole units; the default (scale 2) still steps by 0.01.
    expect(fs('qty:decimal:8|0')->formFields())->toContain('type="number" step="1"');
    expect(fs('price:decimal')->formFields())->toContain('type="number" step="0.01"');
    expect(fs('amount:decimal:14|6')->formFields())->toContain('type="number" step="0.000001"');
});

it('rejects a colon-separated decimal precision instead of silently falling back to (10,2)', function () {
    // `decimal:12:3` (colon, not pipe) used to slip through and quietly become decimal(10,2), corrupting the
    // schema + the DecimalPrecision rule. It must now fail loudly, pointing at the pipe syntax.
    expect(fn () => fs('amount:decimal:12:3'))
        ->toThrow(InvalidArgumentException::class, 'Use a pipe `|`, not a colon `:`');
    expect(fn () => fs('amount:decimal:12:3'))
        ->toThrow(InvalidArgumentException::class, 'decimal precision must be digits');
    // A non-numeric precision is rejected too (no silent fallback).
    expect(fn () => fs('amount:decimal:wide'))
        ->toThrow(InvalidArgumentException::class, 'decimal precision must be digits');
});

it('rejects a decimal whose scale exceeds its precision (an invalid column the DB refuses)', function () {
    expect(fn () => fs('rate:decimal:2|5'))
        ->toThrow(InvalidArgumentException::class, "scale (5) can't exceed its precision (2)");
});

it('bounds the factory fake to the column precision (a small decimal never overflows its own migration)', function () {
    // decimal(4,2) tops out at 99.99 — the factory must not fake up to 1000.
    expect(fs('rate:decimal:4|2')->factoryDefinition())->toContain("'rate' => fake()->randomFloat(2, 1, 99)");
    // A wide column stays capped at 1000 for readable data.
    expect(fs('big:decimal:12|2')->factoryDefinition())->toContain("'big' => fake()->randomFloat(2, 1, 1000)");
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

it('builds a money field with a currency-aware MoneyAmount rule (not a fixed decimals-blind bound)', function () {
    // A fixed between:-99999999999999,… assumed 4 decimals — a >4-decimal currency then 500'd in the cast.
    // The rule now carries the field's currency (matching the cast arg) so the bound tracks its decimals.
    expect(fs('price:money')->storeRules())
        ->toContain("'price' => ['required', 'numeric', new \\Ngos\\AdminCore\\Rules\\MoneyAmount()]")
        ->not->toContain('between:-99999999999999');
    expect(fs('price:money:KHR')->storeRules())
        ->toContain("new \\Ngos\\AdminCore\\Rules\\MoneyAmount('KHR')");
    expect(fs('amount:money:@currency, currency:string')->storeRules())
        ->toContain("new \\Ngos\\AdminCore\\Rules\\MoneyAmount('@currency')");
});

it('bounds an integer rule to the 32-bit column range (a larger value 422s, not 500s on overflow)', function () {
    // A plain `integer` column is a signed 32-bit INT; without a bound, 5000000000 passes `integer`
    // validation then overflows the column (uncaught QueryException / 500 in MySQL strict mode).
    expect(fs('qty:integer')->storeRules())
        ->toContain("'qty' => ['required', 'integer', 'between:-2147483648,2147483647']");
    // A nullable integer keeps the same bound.
    expect(fs('qty:integer?')->storeRules())
        ->toContain("'qty' => ['nullable', 'integer', 'between:-2147483648,2147483647']");
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

it('rejects two fields deriving the same relation method (the model would declare it twice)', function () {
    // category_id:foreign and category:belongsToMany both derive category() — one class body, two
    // declarations, "Cannot redeclare" on first load while make reported success.
    expect(fn () => fs('name:string, category_id:foreign, category:belongsToMany'))
        ->toThrow(InvalidArgumentException::class, "both derive the relation method 'category()'");
});

it('rejects a relation named like a base Eloquent Model method (save, fresh, …)', function () {
    // save:belongsToMany emits public function save(): BelongsToMany — a fatal, incompatible override
    // of Model::save() the moment the generated class loads.
    expect(fn () => fs('save:belongsToMany'))
        ->toThrow(InvalidArgumentException::class, "already exists on Eloquent's base Model");
    expect(fn () => fs('fresh_id:foreign:widgets')) // foreign derives fresh() = Model::fresh()
        ->toThrow(InvalidArgumentException::class, "already exists on Eloquent's base Model");

    // Ordinary relations still parse and emit.
    expect(fs('category_id:foreign, tags:belongsToMany')->relations())
        ->toContain('function category()')
        ->toContain('function tags()');
});

it('generates a working self-referencing belongsToMany (distinct pivot columns + explicit keys)', function () {
    // A self-ref m2m (related categories, prerequisite courses) used to emit the pivot's TWO FK columns
    // both named category_id — a duplicate column that fails `php artisan migrate` outright — and a
    // conventions-only belongsToMany() whose both sides derive that same key.
    $f = fs('name:string, categories:belongsToMany', 'categories')->setClass('Category');

    $schema = $f->extraSchema();
    expect($schema)->toContain("Schema::create('category_category'")
        ->toContain("\$table->foreignId('category_id')->constrained()->cascadeOnDelete();")
        // The far side is related_*, constrained explicitly back to the same table (constrained()
        // would infer a nonexistent related_categories table from the column name).
        ->toContain("\$table->foreignId('related_category_id')->constrained('categories')->cascadeOnDelete();");
    expect(substr_count($schema, "foreignId('category_id')"))->toBe(1); // never the duplicate column

    // The relation names the pivot + both keys explicitly, matching the schema.
    expect($f->relations())
        ->toContain("belongsToMany(\\App\\Models\\Category::class, 'category_category', 'category_id', 'related_category_id')");

    // A normal (non-self) m2m keeps the plain convention call.
    expect(fs('tags:belongsToMany', 'products')->relations())
        ->toContain('belongsToMany(\App\Models\Tag::class);');
});

it('locks a write-once foreign/enum select on edit (disabled), like every other write-once scalar', function () {
    // A write-once (~) scalar renders :readonly on edit; a <select> can't be readonly in HTML, so a
    // write-once foreign/enum must instead render :disabled="(bool) $object" — otherwise the dropdown
    // stays fully editable on the edit form even though the value can never be saved (no UpdateRequest rule).
    $form = fs('category_id:foreign~, status:enum:draft|published~')->setClass('Product')->formFields();

    // Both selects carry the edit-time lock.
    expect(substr_count($form, ':disabled="(bool) $object"'))->toBe(2)
        ->and($form)->toContain('name="category_id"')
        ->and($form)->toContain('name="status"');

    // A plain (not write-once) foreign/enum select stays editable — no disabled attribute leaks in.
    expect(fs('category_id:foreign, status:enum:draft|published')->setClass('Product')->formFields())
        ->not->toContain(':disabled=');

    // A write-once belongsToMany must NOT be disabled: a disabled multi-select posts nothing, which would
    // DETACH every pivot on save. Only the single-value foreign/enum selects get the lock.
    expect(fs('tags:belongsToMany~')->setClass('Product')->formFields())
        ->not->toContain(':disabled=');
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
        ->toContain('source="categories"')                          // searchable + paginated remote select (no eager-load)
        ->toContain("ac_fk_option(\$object, 'category', 'category_id')"); // preselected option, withTrashed-aware + localized
    expect($f->showRows())->toContain('ac_localize($object->category?->name)');

    // and the helper resolves a translatable array to the locale string, passing plain strings through
    expect(ac_localize(['en' => 'Drinks', 'km' => 'x']))->toBe('Drinks');
    expect(ac_localize('Plain'))->toBe('Plain');
    expect(ac_localize(null))->toBe('');
});

it('adds the DB unique constraint for a one-to-one foreign field (foreign^), matching the validation rule', function () {
    $f = fs('profile_id:foreign:profiles^', 'accounts');

    // The foreign match-arm builds its own line and skips the general unique block — so ^ must add ->unique()
    // here, or the DB constraint the `unique:accounts,profile_id` rule promises would never exist.
    expect($f->migrationColumns())
        ->toContain("foreignId('profile_id')->unique()->constrained('profiles')");
    expect($f->storeRules())->toContain("unique:accounts,profile_id");

    // A plain (non-unique) foreign key does NOT get ->unique().
    expect(fs('profile_id:foreign:profiles', 'accounts')->migrationColumns())
        ->toContain("foreignId('profile_id')->constrained('profiles')")
        ->not->toContain('->unique()');
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
    // The self-ref FK is NULLABLE in the rule (matching the forced-nullable migration), so the root row —
    // which has no parent — can be created; a plain 'required' would block it.
    expect($f->storeRules())->toContain("'parent_id' => ['nullable', 'integer', 'exists:categories,id']")
        ->not->toContain("'parent_id' => ['required'");
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

    // Search hits the per-locale VALUES via JSON paths (name->en …), NOT a plain LIKE on the raw column —
    // which would match the JSON KEYS too (searching "en" would match every row's {"en":…}).
    expect($f->getDataColumns())
        ->toContain("->filterColumn('name', function (\$q, \$keyword) {")
        ->toContain("\$sub->orWhere('name->' . \$acLocale, 'like', '%' . \$keyword . '%');");
});

it('the generated translatable filterColumn searches the locale VALUE, not the JSON key', function () {
    config(['admin-core.translation.locales' => ['en' => 'English', 'km' => 'Khmer']]);
    Schema::create('tl_prods', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->id();
        $t->json('name');
    });
    Illuminate\Support\Facades\DB::table('tl_prods')->insert([
        ['name' => json_encode(['en' => 'Phones', 'km' => 'ទូរស័ព្ទ'])],
        ['name' => json_encode(['en' => 'Bicycles', 'km' => 'កង់'])],
    ]);

    // The exact body the generator emits: search each locale's JSON value.
    $search = function ($q, $keyword) {
        $q->where(function ($sub) use ($keyword) {
            foreach (array_keys((array) config('admin-core.translation.locales', ['en' => 'English'])) as $acLocale) {
                $sub->orWhere('name->' . $acLocale, 'like', '%' . $keyword . '%');
            }
        });
    };

    // "en" (a JSON KEY present in every row) must match NOTHING (the pre-fix raw LIKE matched all).
    $q1 = Illuminate\Support\Facades\DB::table('tl_prods');
    $search($q1, 'en');
    expect($q1->count())->toBe(0);

    // A real value substring matches its row (in either locale).
    $q2 = Illuminate\Support\Facades\DB::table('tl_prods');
    $search($q2, 'Phone');
    expect($q2->count())->toBe(1);

    Schema::dropIfExists('tl_prods');
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

it('emits a pivot table once when two belongsToMany fields resolve to the same pivot (no duplicate CREATE)', function () {
    // 'category' and 'categories' both singularize to 'category' → the same pivot 'category_post'. Emitting
    // two Schema::create('category_post', …) would fail `migrate` with "table already exists".
    $f = fs('category:belongsToMany, categories:belongsToMany', 'posts');
    expect(substr_count($f->extraSchema(), "Schema::create('category_post'"))->toBe(1)
        ->and(substr_count($f->extraSchemaDown(), "dropIfExists('category_post')"))->toBe(1);

    // Two DISTINCT pivots are still both created.
    expect(substr_count(fs('tags:belongsToMany, groups:belongsToMany', 'posts')->extraSchema(), 'Schema::create('))->toBe(2);
});

it('handles belongsToMany with a pivot migration and sync', function () {
    $f = fs('tags:belongsToMany');
    expect($f->fillable())->not->toContain('tags');
    expect($f->extraSchema())->toContain("Schema::create('product_tag'");
    expect($f->relations())->toContain('belongsToMany');
    expect($f->serviceBody())->toContain('->sync(');
    // nullable + array — an empty/absent selection is a valid empty relation (not a validation.array 422).
    expect($f->storeRules())
        ->toContain("'tags' => ['nullable', 'array']")
        ->toContain("'tags.*' => ['integer', 'exists:tags,id']");
});

it('validates an empty/absent belongsToMany selection as a valid empty relation', function () {
    // Reproduce the exact generated rule against the real validator: null must pass (sync an empty set),
    // not fail with validation.array as a bare ['array'] rule would.
    $rules = ['tags' => ['nullable', 'array'], 'tags.*' => ['integer']];
    expect(validator(['tags' => null], $rules)->fails())->toBeFalse();  // no tags → valid
    expect(validator([], $rules)->fails())->toBeFalse();                // absent → valid
    expect(validator(['tags' => [1, 2]], $rules)->fails())->toBeFalse(); // a real selection → valid
    expect(validator(['tags' => 'nope'], $rules)->fails())->toBeTrue();  // a non-array → still rejected
});

it('renders a belongsToMany field as a searchable remote multi-select (no whole-table eager-load)', function () {
    $form = fs('tags:belongsToMany')->formFields();

    // Remote + searchable + multiple, mirroring the foreign select (source resolves the related select route).
    expect($form)->toContain('<x-admin-core::select name="tags"')
        ->toContain('source="tags"')
        ->toContain('multiple');

    // Preselect the attached pivot rows (withTrashed-aware via ac_bt_options, so a soft-deleted attached row
    // stays selected and sync() can't silently detach it); never eager-load the whole related table.
    expect($form)->toContain("ac_bt_options(\$object ?? null, 'tags')")
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

it('guards a numeric computed division against divide-by-zero (a zero denominator → 0, not a 500)', function () {
    // Parity with the --derived compiler: a computed `x / qty` with qty = 0 must not throw DivisionByZeroError
    // when the appended accessor is read during list/show/API serialization.
    $acc = fs('total:decimal, qty:integer, avg:computed:total / qty')->accessors();
    expect($acc)->toContain('=== 0.0 ? 0 :');
    // The money branch needs no inline guard — Money::divide() returns null on a zero divisor.
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

// -- composite unique (--unique="a,b") ---------------------------------------------------------------

it('adds a composite unique constraint to the migration', function () {
    $f = fs('order_id:foreign:orders, product_id:foreign:products, qty:integer', 'order_items')
        ->setUniqueGroups([['order_id', 'product_id']]);

    expect($f->uniqueConstraints())->toContain("\$table->unique(['order_id', 'product_id']);");
});

it('validates a composite unique on its first column (where + ignore), importing Rule on update', function () {
    $f = fs('order_id:foreign:orders, product_id:foreign:products', 'order_items')
        ->setUniqueGroups([['order_id', 'product_id']]);

    // Store: fully-qualified Rule + a ->where for the other column.
    expect($f->storeRules())
        ->toContain("\\Illuminate\\Validation\\Rule::unique('order_items', 'order_id')->where('product_id', \$this->input('product_id'))");
    // Update: short Rule (imported) + ignore self + the same ->where.
    expect($f->updateRules())
        ->toContain("Rule::unique('order_items', 'order_id')->ignore(\$this->route('id'), 'id')->where('product_id', \$this->input('product_id'))");
    expect($f->updateUses())->toContain('use Illuminate\Validation\Rule;');
});

it('chains a ->where for every other column of a 3-column group', function () {
    $f = fs('a:integer, b:integer, c:integer', 'things')->setUniqueGroups([['a', 'b', 'c']]);

    expect($f->storeRules())
        ->toContain("Rule::unique('things', 'a')->where('b', \$this->input('b'))->where('c', \$this->input('c'))");
});

it('honours soft-deletes (withoutTrashed) on a composite unique', function () {
    $f = fs('a:integer, b:integer', 'things')->setSoftDeletes(true)->setUniqueGroups([['a', 'b']]);

    expect($f->storeRules())->toContain("->where('b', \$this->input('b'))->withoutTrashed()");
});

it('compares a NOT-NULL boolean composite member as a coerced bool, not raw input (omitted = stored 0)', function () {
    // owner_id + is_primary: when is_primary is omitted (common on an API POST), $this->input() is null →
    // Laravel's ->where(col, null) becomes whereNull, which misses the real stored 0 → the check passes and
    // the DB unique index 500s. $this->boolean() resolves an omitted flag to false (0), matching the store.
    $f = fs('owner_id:foreign:users, is_primary:boolean, product_id:foreign:products', 'memberships')
        ->setUniqueGroups([['owner_id', 'is_primary']]);

    expect($f->storeRules())
        ->toContain("Rule::unique('memberships', 'owner_id')->where('is_primary', \$this->boolean('is_primary'))")
        ->not->toContain("->where('is_primary', \$this->input('is_primary'))");

    // A NULLABLE boolean member DOES store NULL when omitted, so whereNull (via input()) stays correct.
    $nullable = fs('owner_id:foreign:users, is_primary:boolean?, product_id:foreign:products', 'memberships')
        ->setUniqueGroups([['owner_id', 'is_primary']]);
    expect($nullable->storeRules())
        ->toContain("->where('is_primary', \$this->input('is_primary'))");

    // A non-boolean member is unaffected — still raw input().
    expect($f->storeRules())->not->toContain("->where('product_id'"); // product_id isn't in this group
});

it('rejects a composite unique that is one column or references an unknown column', function () {
    expect(fn () => fs('a:integer')->setUniqueGroups([['a']]))
        ->toThrow(InvalidArgumentException::class, 'needs at least two columns');
    expect(fn () => fs('a:integer, b:integer')->setUniqueGroups([['a', 'nope']]))
        ->toThrow(InvalidArgumentException::class, "references 'nope'");
});

it('emits no unique constraint or rule when no groups are set', function () {
    $f = fs('a:integer, b:integer');

    expect($f->uniqueConstraints())->toBe('')
        ->and($f->updateUses())->toBe(''); // no Rule import needed
});

it('rejects a composite unique with a duplicate column or a non-scalar member', function () {
    expect(fn () => fs('a:integer, b:integer')->setUniqueGroups([['a', 'a']]))   // dup -> broken SQL
        ->toThrow(InvalidArgumentException::class, 'repeats a column');
    expect(fn () => fs('sku:string, meta:json')->setUniqueGroups([['sku', 'meta']])) // json -> bad index/where
        ->toThrow(InvalidArgumentException::class, "can't include 'meta'");
    expect(fn () => fs('sku:string, note:text')->setUniqueGroups([['sku', 'note']]))
        ->toThrow(InvalidArgumentException::class, "can't include 'note'");
});

it('skips the FormRequest rule (DB-only) when a composite member is a system or write-once field', function () {
    // A system first column: the value isn't submitted, so a ->where would falsely reject — DB constraint only.
    $sys = fs('owner_id:integer@, product_id:foreign:products', 'memberships')->setUniqueGroups([['owner_id', 'product_id']]);
    expect($sys->uniqueConstraints())->toContain("\$table->unique(['owner_id', 'product_id']);") // DB backstop present
        ->and($sys->storeRules())->not->toContain('Rule::unique(')   // no (false-rejecting) form rule
        ->and($sys->updateRules())->not->toContain('Rule::unique(');
    expect($sys->uniqueGroupsWithoutFormValidation())->toBe([['owner_id', 'product_id']]); // surfaced to warn

    // A write-once first column: validated on create (it's submitted), but locked/absent on update.
    $wo = fs('code:string~, branch_id:integer')->setUniqueGroups([['code', 'branch_id']]);
    expect($wo->storeRules())->toContain('Rule::unique(')            // present on create
        ->and($wo->updateRules())->not->toContain('Rule::unique('); // absent on update (locked)
});

// -- sequence (auto document number) -----------------------------------------------------------------

it('makes a sequence field a system, unique column with an auto-numbering boot hook', function () {
    $f = fs('invoice_no:sequence:INV, customer:string', 'invoices');

    expect($f->bootBody())->toContain("\$model->invoice_no ??= \\Ngos\\AdminCore\\Support\\Sequence::next('invoices.invoice_no', 'INV-')");
    expect($f->migrationColumns())->toContain("\$table->string('invoice_no')->nullable()->unique();");
    expect($f->fillable())->toBe("'customer'");                 // system → not mass-assignable
    expect($f->storeRules())->not->toContain("'invoice_no'");   // never validated
    expect(fs('no:sequence', 'docs')->bootBody())->toContain("Sequence::next('docs.no')"); // bare → no prefix
});

it('rejects a sequence prefix with characters that could break the generated PHP', function () {
    expect(fn () => fs("no:sequence:IN'V"))->toThrow(InvalidArgumentException::class, 'may use only letters');
    expect(fn () => fs('no:sequence:A\\B'))->toThrow(InvalidArgumentException::class, 'may use only letters');
    // Common separators are fine.
    expect(fn () => fs('no:sequence:INV/2026', 'docs'))->not->toThrow(InvalidArgumentException::class);
});

// -- Relation-driven derived columns (--derived) -----------------------------------------------------

it('compiles a --derived saving hook: relation compute + copy, fetched once', function () {
    $f = fs('unit_id:foreign:units, qty:decimal:12|3, qty_base:decimal:12|3, variant_id:foreign:variants', 'items')
        ->setDerived(['qty_base' => 'qty * unit_id.conversion_factor', 'variant_id' => 'unit_id.variant_id']);

    $boot = $f->modelBoot();
    expect($boot)
        ->toContain('static::saving(function (self $model) {')
        ->toContain('$unit = ac_find(\App\Models\Unit::class, $model->unit_id);') // withTrashed-aware fetch
        ->toContain('$model->qty_base = ((float) ($model->qty ?? 0) * (float) ($unit?->conversion_factor ?? 0));')
        ->toContain('$model->variant_id = $unit?->variant_id;'); // copy: no float cast
    // The FK is fetched exactly once even though two derived columns reference it.
    expect(substr_count($boot, 'ac_find(\App\Models\Unit::class'))->toBe(1);
});

it('guards a divide in a derived expression against divide-by-zero', function () {
    $boot = fs('unit_id:foreign:units, total:decimal:12|2, per_unit:decimal:12|2', 'items')
        ->setDerived(['per_unit' => 'total / unit_id.pack_size'])->modelBoot();

    expect($boot)->toContain('== 0.0 ? 0 :'); // blank/zero related attribute → 0, not a runtime error
});

it('rejects a --derived target that is not a stored column', function () {
    expect(fn () => fs('unit_id:foreign:units', 'items')->setDerived(['nope' => 'unit_id.x']))
        ->toThrow(InvalidArgumentException::class, 'must be a stored column');
});

it('rejects a relation reference whose column is not a declared foreign', function () {
    expect(fn () => fs('unit_id:foreign:units, qty:decimal:12|2, qty_base:decimal:12|2', 'items')->setDerived(['qty_base' => 'qty * missing_id.factor']))
        ->toThrow(InvalidArgumentException::class, "isn't a foreign column");
});

it('rejects a bare reference to an undeclared column', function () {
    expect(fn () => fs('unit_id:foreign:units, qty_base:decimal:12|2', 'items')->setDerived(['qty_base' => 'ghost * 2']))
        ->toThrow(InvalidArgumentException::class, "isn't a stored column");
});

it('rejects a malformed derived expression (trailing operator / unbalanced parens)', function () {
    expect(fn () => fs('unit_id:foreign:units, x:decimal:12|2', 'items')->setDerived(['x' => 'unit_id.factor *']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => fs('unit_id:foreign:units, x:decimal:12|2', 'items')->setDerived(['x' => '(unit_id.factor']))
        ->toThrow(InvalidArgumentException::class, 'unbalanced');
});

it('rejects a derived column that references itself (would drift on every save)', function () {
    expect(fn () => fs('total:decimal:12|2', 'items')->setDerived(['total' => 'total * 2']))
        ->toThrow(InvalidArgumentException::class, "can't reference itself");
});

it('excludes a derived target from fillable / rules / form but keeps it a displayed column', function () {
    $f = fs('unit_id:foreign:units, qty:decimal:12|3, qty_base:decimal:12|3', 'items')
        ->setDerived(['qty_base' => 'qty * unit_id.conversion_factor']);

    expect($f->fillable())->not->toContain('qty_base');        // set by the hook, never mass-assigned
    expect($f->storeRules())->not->toContain("'qty_base'");    // never validated (no "required" on a blank)
    expect($f->formFields())->not->toContain('name="qty_base"'); // no editable input
    // …but it stays a real, nullable, DISPLAYED column.
    expect($f->migrationColumns())->toContain("\$table->decimal('qty_base', 12, 3)->nullable();");
    expect($f->showRows())->toContain('qty_base');
});
