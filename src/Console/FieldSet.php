<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Support\Str;

/**
 * Parses the `--fields` DSL and builds every code snippet the generator needs.
 *
 * DSL:  "name:string, price:decimal?, status:enum:draft|published,
 *        category_id:foreign, avatar:image?, brochure:file?, tags:belongsToMany"
 *   - scalar   string text integer decimal boolean date datetime email
 *   - decimal  optional precision|scale (pipe-separated):  price:decimal:12|4  (defaults to 10,2)
 *   - enum     values piped:  status:enum:draft|published|archived
 *   - foreign  column ending in _id:  category_id:foreign  (-> belongsTo). Add an explicit
 *              target table for a self-reference / tree or a non-conventional name:
 *              parent_id:foreign:categories,  author_id:foreign:users
 *   - image    file upload, stored on the public disk, thumbnailed in the table
 *   - file     any file upload
 *   - translatable  per-locale JSON (name:translatable)  ->  multi-language input + auto-translate
 *   - belongsToMany (aliases manyToMany, m2m)  ->  pivot table + multi-select + sync
 *   - hasMany  master-detail line items:  lines:hasMany:order_items  (or lines:hasMany, child inferred)
 *              ->  parent relation + a repeater form block + service sync + a generated row partial
 *   - modifiers trailing  ?  = nullable,  ^  = unique,  #  = index   (e.g. slug:string^?, status:enum:a|b#)
 */
class FieldSet
{
    /**
     * Every type the DSL accepts (after modifier-stripping + enum/m2m normalisation). A token whose
     * type isn't here is a typo or malformed syntax — rejecting it turns silent schema corruption
     * (e.g. a comma'd enum `status:enum(a,b)` leaking columns named `b` and `c)`) into a clear error.
     */
    private const TYPES = [
        'string', 'text', 'richtext', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'time',
        'email', 'url', 'password', 'slug', 'json', 'translatable', 'image', 'file',
        'foreign', 'belongsToMany', 'hasMany', 'enum', 'auth', 'sku',
    ];

    /** @var array<int, array<string, mixed>> */
    private array $fields;

    private string $table = 'dummyModels';

    private bool $uuid = false;

    private bool $softDeletes = false;

    private bool $audit = false;

    private bool $sortable = false;

    private string $class = 'DummyClass';

    private ?bool $hasNameOverride = null;

    public function __construct(?string $raw)
    {
        $this->fields = $this->parse($raw);
    }

    /**
     * Tell the set whether the model already has a `name` column (used by the add-field
     * command, whose token set won't contain the pre-existing `name`). Drives the slug
     * auto-derive in the booted() hook.
     */
    public function setHasName(bool $hasName): self
    {
        $this->hasNameOverride = $hasName;

        return $this;
    }

    public function setClass(string $class): self
    {
        $this->class = $class;

        return $this;
    }

    /** The backed-enum class name for an enum field, e.g. Post + status → PostStatus. */
    public function enumClass(array $f): string
    {
        return $this->class . Str::studly($f['name']);
    }

    /**
     * Enum classes to generate: [['class' => 'PostStatus', 'cases' => ['draft' => 'Draft', …]], …].
     * One per enum field — the single source of truth for that field's allowed values.
     */
    public function enumDefinitions(): array
    {
        $defs = [];
        foreach ($this->fields as $f) {
            if ($f['type'] === 'enum') {
                $cases = [];
                foreach ($f['enum'] as $value) {
                    $cases[Str::studly($value)] = $value;
                }
                $defs[] = ['class' => $this->enumClass($f), 'cases' => $cases];
            }
        }

        return $defs;
    }

    public function setSoftDeletes(bool $softDeletes): self
    {
        $this->softDeletes = $softDeletes;

        return $this;
    }

    public function setAudit(bool $audit): self
    {
        $this->audit = $audit;

        return $this;
    }

    public function setSortable(bool $sortable): self
    {
        $this->sortable = $sortable;

        return $this;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    /** The `$table->integer('sort')...` migration line, or empty. */
    public function sortColumn(): string
    {
        return $this->sortable ? "            \$table->integer('sort')->default(0);" : '';
    }

    public function setTable(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function setUuid(bool $uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }

    /**
     * Primary-key line(s) for the migration. Hybrid strategy: always a fast bigint
     * `id` PK, plus a unique public `uuid` column for URLs/APIs when enabled.
     */
    public function primaryKey(): string
    {
        if (! $this->uuid) {
            return '$table->id();';
        }

        return "\$table->id();\n            \$table->uuid('uuid')->unique();";
    }

    /** The model's trait list (after `use `). */
    public function modelTraits(): string
    {
        $traits = ['HasFactory'];
        if ($this->uuid) {
            $traits[] = 'HasPublicUuid';
        }
        if ($this->softDeletes) {
            $traits[] = 'SoftDeletes';
        }
        if ($this->audit) {
            $traits[] = 'LogsActivity';
        }

        return implode(', ', $traits);
    }

    /** The `$table->softDeletes();` migration line, or empty. */
    public function softDeletesColumn(): string
    {
        return $this->softDeletes ? '            $table->softDeletes();' : '';
    }

    private function parse(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [$this->field('name', 'string')];
        }

        $fields = [];
        foreach (explode(',', $raw) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            [$name, $spec] = array_pad(explode(':', $token, 2), 2, 'string');
            $name = trim($name);
            $spec = trim($spec);

            $nullable = $unique = $writeOnce = $system = $index = false;
            while ($spec !== '' && in_array(substr($spec, -1), ['?', '^', '~', '@', '#'], true)) {
                match (substr($spec, -1)) {
                    '?' => $nullable = true,
                    '^' => $unique = true,
                    '~' => $writeOnce = true, // settable on create, locked on update
                    '@' => $system = true,    // set by trusted code only (never user-fillable)
                    '#' => $index = true,     // plain (non-unique) database index
                    default => null,          // unreachable (guarded by the while), keeps match exhaustive
                };
                $spec = substr($spec, 0, -1);
            }

            $enum = [];
            if (str_starts_with($spec, 'enum:')) {
                $enum = array_values(array_filter(array_map('trim', explode('|', substr($spec, 5)))));
                $spec = 'enum';
            }

            // Optional decimal precision/scale: `price:decimal:12|4` (pipe-separated like enum, since the
            // field list is comma-split; defaults to 10,2 when omitted).
            $decimalPrecision = $decimalScale = null;
            if (str_starts_with($spec, 'decimal:')) {
                $parts = array_map('trim', explode('|', substr($spec, 8)));
                $decimalPrecision = ctype_digit($parts[0]) ? (int) $parts[0] : null;
                $decimalScale = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null;
                $spec = 'decimal';
            }

            // Explicit FK target: `parent_id:foreign:categories` (self-reference / tree) or a column whose
            // name doesn't match the table convention (`author_id:foreign:users`). Without the target the
            // table is inferred from the column (parent_id -> parents), which breaks self-refs.
            $foreignTable = null;
            if (str_starts_with($spec, 'foreign:')) {
                $foreignTable = trim(substr($spec, 8));
                $spec = 'foreign';
            }

            if (in_array($spec, ['manytomany', 'm2m'], true)) {
                $spec = 'belongsToMany';
            }

            // hasMany line-items (master-detail): `lines:hasMany:order_items` (explicit child table) or
            // `lines:hasMany` (child table inferred from the field name). No parent column — a relation only.
            $hasManyTable = null;
            if (str_starts_with(strtolower($spec), 'hasmany')) {
                $hasManyTable = str_contains($spec, ':') ? trim(substr($spec, strpos($spec, ':') + 1)) : null;
                $spec = 'hasMany';
            }

            $type = $spec ?: 'string';

            // Reject malformed tokens before they reach the migration. The usual culprit is a comma'd or
            // parenthesised enum (`status:enum(a,b,c)`) — the outer split shatters it, so values arrive as
            // their own "fields" with names like `c)`. Fail loudly with the right syntax instead.
            if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
                throw new \InvalidArgumentException(
                    "admin-core: invalid field name '{$name}' in \"{$token}\". Names must be identifiers "
                    . '(letters, numbers, underscores). Enum values are pipe-separated, not comma/parenthesised: '
                    . 'status:enum:draft|published|archived',
                );
            }
            if (! in_array($type, self::TYPES, true)) {
                throw new \InvalidArgumentException(
                    "admin-core: unknown field type '{$type}' for '{$name}'. Valid types: "
                    . implode(', ', self::TYPES) . '. Enum syntax: status:enum:draft|published.',
                );
            }
            if ($type === 'enum') {
                $this->assertEnumCases($name, $enum);
            }

            $field = $this->field($name, $type, $nullable, $unique, $enum, $writeOnce, $system, $index, $foreignTable);
            if ($type === 'decimal') {
                $field['precision'] = $decimalPrecision ?? 10;
                $field['scale'] = $decimalScale ?? 2;
            }
            if ($type === 'hasMany') {
                $child = $hasManyTable !== null && $hasManyTable !== ''
                    ? Str::snake($hasManyTable)
                    : Str::snake(Str::pluralStudly($name));
                $field['relation'] = Str::camel($name);
                $field['relTable'] = $child;
                $field['relModel'] = Str::studly(Str::singular($child));
            }
            $fields[] = $field;
        }

        return $fields ?: [$this->field('name', 'string')];
    }

    /**
     * Each enum value becomes a PHP enum case (`case Draft = 'draft';`), so the StudlyCase of the value
     * must be a legal, unique identifier. Without this a numeric value (`enum:1|2|3`) generates `case 1 = …`
     * and a colliding pair (`in-progress|in_progress` → `InProgress`) a duplicate case — both fatal PHP that
     * the scaffolder used to write while reporting success.
     *
     * @param  array<int, string>  $values
     */
    private function assertEnumCases(string $name, array $values): void
    {
        if ($values === []) {
            throw new \InvalidArgumentException(
                "admin-core: enum field '{$name}' has no values. Use: {$name}:enum:draft|published|archived",
            );
        }

        $seen = [];
        foreach ($values as $value) {
            $case = Str::studly($value);
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $case)) {
                throw new \InvalidArgumentException(
                    "admin-core: enum value '{$value}' (field '{$name}') can't become a PHP case name. Each "
                    . 'value must start with a letter and be alphanumeric — for numbers use a prefix, e.g. enum:p1|p2|p3.',
                );
            }
            if (isset($seen[$case])) {
                throw new \InvalidArgumentException(
                    "admin-core: enum values '{$seen[$case]}' and '{$value}' (field '{$name}') both map to the "
                    . "case '{$case}'. Use values that stay distinct after StudlyCase.",
                );
            }
            $seen[$case] = $value;
        }
    }

    private function field(string $name, string $type, bool $nullable = false, bool $unique = false, array $enum = [], bool $writeOnce = false, bool $system = false, bool $index = false, ?string $foreignTable = null): array
    {
        $f = compact('name', 'type', 'nullable', 'unique', 'enum', 'writeOnce', 'system', 'index');

        // Typed system helpers — these set themselves from trusted code, never user input.
        if (in_array($type, ['auth', 'sku'], true)) {
            $f['system'] = true;
        }

        // A slug is always unique and nullable (left blank → derived from `name`
        // in the model's creating hook, so validation must allow the empty value).
        if ($type === 'slug') {
            $f['unique'] = true;
            $f['nullable'] = true;
        }

        if ($type === 'foreign') {
            $base = Str::beforeLast($name, '_id');
            $f['relation'] = Str::camel($base);
            if ($foreignTable !== null && $foreignTable !== '') {
                // explicit target: parent_id:foreign:categories (self-ref) or author_id:foreign:users
                $f['relTable'] = Str::snake($foreignTable);
                $f['relModel'] = Str::studly(Str::singular($f['relTable']));
            } else {
                $f['relModel'] = Str::studly(Str::singular($base));
                $f['relTable'] = Str::snake(Str::pluralStudly($base));
            }
        }

        if ($type === 'belongsToMany') {
            $f['relation'] = Str::camel($name);
            $f['relModel'] = Str::studly(Str::singular($name));
            $f['relTable'] = Str::snake(Str::pluralStudly($name));
        }

        return $f;
    }

    // ---- predicates --------------------------------------------------

    private function isColumn(array $f): bool
    {
        // belongsToMany (pivot) and hasMany (child rows) are relations, not columns on this table.
        return ! in_array($f['type'], ['belongsToMany', 'hasMany'], true);
    }

    private function isFile(array $f): bool
    {
        return in_array($f['type'], ['image', 'file'], true);
    }

    private function hasName(): bool
    {
        return $this->hasNameOverride ?? collect($this->fields)->contains(fn ($f) => $f['name'] === 'name');
    }

    /** True when the `name` column is a translatable (per-locale array) field. */
    private function nameIsTranslatable(): bool
    {
        return collect($this->fields)->contains(fn ($f) => $f['name'] === 'name' && $f['type'] === 'translatable');
    }

    private function hasFiles(): bool
    {
        return collect($this->fields)->contains(fn ($f) => $this->isFile($f));
    }

    private function hasManyToMany(): bool
    {
        return collect($this->fields)->contains(fn ($f) => $f['type'] === 'belongsToMany');
    }

    private function hasHasMany(): bool
    {
        return collect($this->fields)->contains(fn ($f) => $f['type'] === 'hasMany');
    }

    /** @return array<int, array<string, mixed>> the hasMany (line-item) fields */
    private function hasManyFields(): array
    {
        return array_values(array_filter($this->fields, fn ($f) => $f['type'] === 'hasMany'));
    }

    /**
     * A generated row-partial (one per hasMany field) for the master-detail repeater. It's a starting
     * point — the wiring (repeater + sync + validation) is complete, but you lay out the child's real
     * columns in place of the example input. Keyed by field name; the make command writes each file.
     *
     * @return array<string, string>
     */
    public function hasManyRowPartials(): array
    {
        $parentModel = Str::studly(Str::singular($this->table));
        $out = [];
        foreach ($this->hasManyFields() as $f) {
            $model = $f['relModel'];
            $label = $this->label($f['relation']);
            $out[$f['name']] = <<<BLADE
{{-- One {$model} row for the "{$label}" repeater on the {$parentModel} form. The repeater passes \$name,
     \$index and \$row. Replace the example column with one input per {$model} field, named
     \$name[\$index][field]; the hidden id tracks existing rows so they update in place. --}}
@php(\$r = (array) (\$row ?? []))
<div class="row g-2 align-items-end mb-2" data-ac-repeater-row>
    <input type="hidden" name="{{ \$name }}[{{ \$index }}][id]" value="{{ \$r['id'] ?? '' }}">
    <div class="col">
        <label class="form-label small mb-1">Example</label>
        <input type="text" name="{{ \$name }}[{{ \$index }}][example]" class="form-control form-control-sm" value="{{ \$r['example'] ?? '' }}">
    </div>
    <div class="col-auto d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-link text-danger p-0" data-ac-repeater-remove title="Remove"><i class="bi bi-x-lg"></i></button>
    </div>
</div>
BLADE;
        }

        return $out;
    }

    private function hasForeign(): bool
    {
        return collect($this->fields)->contains(fn ($f) => $f['type'] === 'foreign');
    }

    private function hasEnum(): bool
    {
        return collect($this->fields)->contains(fn ($f) => $f['type'] === 'enum');
    }

    private function needsServiceBody(): bool
    {
        return $this->hasFiles() || $this->hasManyToMany() || $this->hasHasMany();
    }

    private function label(string $name): string
    {
        return Str::headline($name);
    }

    // ---- Migration ---------------------------------------------------

    public function migrationColumns(): string
    {
        $lines = [];
        foreach ($this->fields as $f) {
            if (! $this->isColumn($f)) {
                continue;
            }
            $col = $f['name'];
            $n = $f['nullable'];
            $line = match ($f['type']) {
                'text', 'richtext' => "\$table->text('{$col}')",
                'integer' => "\$table->integer('{$col}')",
                'decimal' => "\$table->decimal('{$col}', " . ($f['precision'] ?? 10) . ', ' . ($f['scale'] ?? 2) . ')',
                'boolean' => "\$table->boolean('{$col}')" . ($n ? '' : '->default(false)'),
                'date' => "\$table->date('{$col}')",
                'datetime' => "\$table->dateTime('{$col}')",
                'time' => "\$table->time('{$col}')",
                'json', 'translatable' => "\$table->json('{$col}')",
                'image', 'file' => "\$table->string('{$col}')",
                // A self-referencing FK (e.g. parent_id -> same table) must be nullable: the root row has no
                // parent, and the factory seeds null for it. Force nullable + nullOnDelete in that case.
                'foreign' => "\$table->foreignId('{$col}')" . ($n || $f['relTable'] === $this->table ? '->nullable()' : '') . "->constrained('{$f['relTable']}')" . ($n || $f['relTable'] === $this->table ? '->nullOnDelete()' : '->cascadeOnDelete()'),
                'auth' => "\$table->foreignId('{$col}')->nullable()->constrained('users')->nullOnDelete()", // set from auth()->id()
                default => "\$table->string('{$col}')", // also covers 'sku' (a string, made nullable below as a system field)
            };
            if (! in_array($f['type'], ['foreign', 'auth'], true)) {
                // uploads are stored as a nullable path string regardless.
                // System fields are filled by a hook (scaffolded as a TODO), so keep them
                // nullable — the generated code runs before you wire the real value up.
                if ($n || $this->isFile($f) || ! empty($f['system'])) {
                    $line .= '->nullable()';
                }
                if ($f['unique']) {
                    $line .= '->unique()';
                } elseif (! empty($f['index'])) {
                    // A unique constraint already creates an index, so only add a plain
                    // one when the column isn't unique. (Foreign keys index themselves.)
                    $line .= '->index()';
                }
            }
            $lines[] = '            ' . $line . ';';
        }

        return implode("\n", $lines);
    }

    public function extraSchema(): string
    {
        $blocks = [];
        $self = Str::singular($this->table);
        foreach ($this->fields as $f) {
            if ($f['type'] !== 'belongsToMany') {
                continue;
            }
            $other = Str::singular($f['relTable']);
            $pair = [$self, $other];
            sort($pair);
            $pivot = implode('_', $pair);
            $blocks[] = <<<PHP

        Schema::create('{$pivot}', function (Blueprint \$table) {
            \$table->foreignId('{$self}_id')->constrained()->cascadeOnDelete();
            \$table->foreignId('{$other}_id')->constrained()->cascadeOnDelete();
        });
PHP;
        }

        return implode("\n", $blocks);
    }

    // ---- Model -------------------------------------------------------

    public function fillable(): string
    {
        return collect($this->fields)
            ->filter(fn ($f) => $this->isColumn($f) && empty($f['system'])) // system fields are set by trusted code, never mass-assigned
            ->map(fn ($f) => "'{$f['name']}'")
            ->implode(', ');
    }

    /** Quoted, comma-joined names of password (hashed) columns — kept out of array/JSON output. */
    public function hiddenColumns(): string
    {
        return collect($this->fields)
            ->filter(fn ($f) => $f['type'] === 'password')
            ->map(fn ($f) => "'{$f['name']}'")
            ->implode(', ');
    }

    /** The `protected $hidden = [...]` declaration for password columns, or '' when there are none. */
    public function hidden(): string
    {
        $cols = $this->hiddenColumns();

        return $cols === '' ? '' : "\n\n    protected \$hidden = [{$cols}];";
    }

    public function modelUses(): string
    {
        $uses = [];
        if ($this->uuid) {
            $uses[] = 'use Ngos\AdminCore\Concerns\HasPublicUuid;';
        }
        if ($this->hasForeign()) {
            $uses[] = 'use Illuminate\Database\Eloquent\Relations\BelongsTo;';
        }
        if ($this->hasManyToMany()) {
            $uses[] = 'use Illuminate\Database\Eloquent\Relations\BelongsToMany;';
        }
        if ($this->hasHasMany()) {
            $uses[] = 'use Illuminate\Database\Eloquent\Relations\HasMany;';
        }
        if ($this->softDeletes) {
            $uses[] = 'use Illuminate\Database\Eloquent\SoftDeletes;';
        }
        if ($this->audit) {
            $uses[] = 'use Ngos\AdminCore\Concerns\LogsActivity;';
        }

        return $uses ? implode("\n", $uses) . "\n" : '';
    }

    /**
     * A `booted()` hook scaffold for system (`@`) fields — set by trusted code, never
     * mass-assigned. Each line is a TODO so you fill in the real value (auth id, code…).
     */
    public function modelBoot(): string
    {
        $body = $this->bootBody();

        if ($body === '') {
            return '';
        }

        return <<<PHP


    protected static function booted(): void
    {
        static::creating(function (self \$model) {
$body
        });
    }
PHP;
    }

    /** The `static::creating` assignment lines for system/slug fields, or '' when none apply. */
    public function bootBody(): string
    {
        $assigns = [];
        foreach ($this->fields as $f) {
            if (! empty($f['system']) && $this->isColumn($f)) {
                $assigns[] = match ($f['type']) {
                    'auth' => "                \$model->{$f['name']} = auth()->id();",
                    'sku' => "                \$model->{$f['name']} = \\Illuminate\\Support\\Str::upper(\\Illuminate\\Support\\Str::random(10));",
                    default => "                \$model->{$f['name']} = null; // TODO: set {$f['name']} from trusted code",
                };
            }
            // A blank slug derives from the `name` field when one exists. When `name` is translatable
            // (an array of locales), derive from the default locale so Str::slug() never sees an array.
            if ($f['type'] === 'slug' && $this->hasName()) {
                if ($this->nameIsTranslatable()) {
                    $d = (string) config('admin-core.translation.default', 'en');
                    $assigns[] = "                \$model->{$f['name']} ??= \\Illuminate\\Support\\Str::slug(is_array(\$model->name) ? (\$model->name['{$d}'] ?? collect(\$model->name)->first()) : \$model->name);";
                } else {
                    $assigns[] = "                \$model->{$f['name']} ??= \\Illuminate\\Support\\Str::slug(\$model->name);";
                }
            }
        }

        return implode("\n", $assigns);
    }

    /**
     * Fake-upload lines for the generated feature test — the factory sets file
     * columns to null, so a required image/file would fail the store rule. Each
     * mutates $payload with a faked upload. Empty when the resource has no files.
     */
    public function testFilePayload(): string
    {
        $lines = [];
        foreach ($this->fields as $f) {
            if ($f['type'] === 'image') {
                $lines[] = "        \$payload['{$f['name']}'] = \\Illuminate\\Http\\UploadedFile::fake()->image('{$f['name']}.jpg');";
            }
            if ($f['type'] === 'file') {
                $lines[] = "        \$payload['{$f['name']}'] = \\Illuminate\\Http\\UploadedFile::fake()->create('{$f['name']}.pdf', 10);";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * JSON resource body — one line per field (the public uuid `id` and the
     * timestamps live in the stub). Passwords and the owner FK are never exposed;
     * uploads become public URLs and relations resolve to their `name`.
     */
    public function resourceFields(): string
    {
        $lines = [];
        foreach ($this->fields as $f) {
            if (in_array($f['type'], ['password', 'auth'], true)) {
                continue;
            }
            if ($f['type'] === 'foreign') {
                // The FK id (so ?filter[{col}] works — the API whitelists it) + the readable related name.
                $lines[] = "            '{$f['name']}' => \$this->{$f['name']},";
                $lines[] = "            '{$f['relation']}' => ac_localize(\$this->{$f['relation']}?->name),";
                continue;
            }
            if ($f['type'] === 'belongsToMany') {
                $lines[] = "            '{$f['relation']}' => \$this->whenLoaded('{$f['relation']}', fn () => \$this->{$f['relation']}->map(fn (\$i) => ac_localize(\$i->name))),";
                continue;
            }
            if ($f['type'] === 'hasMany') {
                $lines[] = "            '{$f['relation']}' => \$this->whenLoaded('{$f['relation']}'),";
                continue;
            }
            if (in_array($f['type'], ['image', 'file'], true)) {
                $lines[] = "            '{$f['name']}' => \$this->{$f['name']} ? \\Ngos\\AdminCore\\Support\\Media::url(\$this->{$f['name']}) : null,";
                continue;
            }
            $lines[] = "            '{$f['name']}' => \$this->{$f['name']},";
        }

        return implode("\n", $lines);
    }

    /** API `?search=` columns — text-like fields the term matches with LIKE. */
    public function apiSearchable(): string
    {
        return $this->apiColumns(['string', 'text', 'email', 'slug', 'url']);
    }

    /** API `?sort=` columns — scalar fields plus created_at (whitelist). */
    public function apiSortable(): string
    {
        $cols = $this->apiColumns(['string', 'integer', 'decimal', 'date', 'datetime', 'time', 'boolean', 'enum', 'email', 'slug', 'url']);

        return $cols === '' ? "'created_at'" : $cols . ", 'created_at'";
    }

    /** API `?filter[col]=` columns — exact-match fields (enum/foreign/boolean). */
    public function apiFilterable(): string
    {
        return $this->apiColumns(['enum', 'foreign', 'boolean']);
    }

    /** Quoted, comma-joined column names for the given field types (non-system, real columns). */
    private function apiColumns(array $types): string
    {
        $cols = [];
        foreach ($this->fields as $f) {
            if (in_array($f['type'], $types, true) && empty($f['system']) && $this->isColumn($f)) {
                $cols[] = "'{$f['name']}'";
            }
        }

        return implode(', ', $cols);
    }

    /** Field-aware factory definition lines. */
    public function factoryDefinition(): string
    {
        $lines = [];
        foreach ($this->fields as $f) {
            if (! $this->isColumn($f) || ! empty($f['system'])) {
                continue; // belongsToMany via relationships; system fields set by hook
            }
            $col = $f['name'];
            $fake = match (true) {
                // translatable first — it's a per-locale array regardless of the column name (e.g. `name`).
                $f['type'] === 'translatable' => '[' . implode(', ', array_map(fn ($l) => "'{$l}' => fake()->words(2, true)", array_keys((array) config('admin-core.translation.locales', ['en' => 'English'])))) . ']',
                $f['name'] === 'name' => 'fake()->name()',
                $f['name'] === 'email' || $f['type'] === 'email' => 'fake()->safeEmail()',
                $f['type'] === 'text' => 'fake()->paragraph()',
                $f['type'] === 'integer' => 'fake()->numberBetween(1, 1000)',
                $f['type'] === 'decimal' => 'fake()->randomFloat(' . ($f['scale'] ?? 2) . ', 1, 1000)',
                $f['type'] === 'boolean' => 'fake()->boolean()',
                $f['type'] === 'date' => 'fake()->date()',
                $f['type'] === 'datetime' => 'fake()->dateTime()',
                $f['type'] === 'time' => "fake()->time('H:i')",
                $f['type'] === 'url' => 'fake()->url()',
                $f['type'] === 'slug' => 'fake()->unique()->slug()',
                $f['type'] === 'json' => "['key' => fake()->word()]",
                $f['type'] === 'richtext' => "'<p>' . fake()->paragraph() . '</p>'",
                $f['type'] === 'password' => "'password'", // hashed by the model's 'hashed' cast
                $f['type'] === 'enum' => "fake()->randomElement(\\App\\Enums\\{$this->enumClass($f)}::cases())",
                $f['type'] === 'foreign' => $f['relTable'] === $this->table ? 'null' : "\\App\\Models\\{$f['relModel']}::factory()",
                in_array($f['type'], ['image', 'file'], true) => 'null',
                default => 'fake()->words(3, true)',
            };
            $lines[] = "            '{$col}' => {$fake},";
        }

        return implode("\n", $lines);
    }

    public function relations(): string
    {
        $methods = [];
        foreach ($this->fields as $f) {
            if ($f['type'] === 'foreign') {
                $methods[] = <<<PHP

    public function {$f['relation']}(): BelongsTo
    {
        return \$this->belongsTo(\\App\\Models\\{$f['relModel']}::class);
    }
PHP;
            }
            if ($f['type'] === 'belongsToMany') {
                $methods[] = <<<PHP

    public function {$f['relation']}(): BelongsToMany
    {
        return \$this->belongsToMany(\\App\\Models\\{$f['relModel']}::class);
    }
PHP;
            }
            if ($f['type'] === 'hasMany') {
                $methods[] = <<<PHP

    public function {$f['relation']}(): HasMany
    {
        return \$this->hasMany(\\App\\Models\\{$f['relModel']}::class);
    }
PHP;
            }
        }

        return implode("\n", $methods);
    }

    /** The cast expression for one field, or null when it needs none. */
    public function fieldCast(array $f): ?string
    {
        if ($f['type'] === 'enum') {
            return '\\App\\Enums\\' . $this->enumClass($f) . '::class';
        }

        return match ($f['type']) {
            'boolean' => "'boolean'",
            'date' => "'date'",
            'datetime' => "'datetime'",
            'decimal' => "'decimal:" . ($f['scale'] ?? 2) . "'",
            'json', 'translatable' => "'array'",
            'password' => "'hashed'",
            default => null,
        };
    }

    /**
     * A `casts()` method so domain types come back correctly — booleans as
     * bool, date/datetime as Carbon, decimals as fixed-precision strings.
     * Custom columns aren't auto-cast by Eloquent, so without this a checkbox
     * field reads as 1/0 and a date reads as a plain string. Empty (omitted)
     * when the resource has no castable columns.
     */
    public function casts(): string
    {
        $casts = [];
        foreach ($this->fields as $f) {
            $cast = $this->fieldCast($f);
            if ($cast !== null) {
                $casts[] = "            '{$f['name']}' => {$cast},";
            }
        }

        if (! $casts) {
            return '';
        }

        $body = implode("\n", $casts);

        return <<<PHP

    protected function casts(): array
    {
        return [
$body
        ];
    }

PHP;
    }

    // ---- Validation --------------------------------------------------

    public function storeRules(): string
    {
        return $this->rules(false);
    }

    public function updateRules(): string
    {
        return $this->rules(true);
    }

    public function updateUses(): string
    {
        return collect($this->fields)->contains(fn ($f) => $f['unique'])
            ? "use Illuminate\\Validation\\Rule;\n"
            : '';
    }

    private function rules(bool $update): string
    {
        $lines = [];
        foreach ($this->fields as $f) {
            // System fields are never validated (set by trusted code); write-once
            // fields have no update rule so update() can never change them.
            if (! empty($f['system']) || ($update && ! empty($f['writeOnce']))) {
                continue;
            }
            $required = $f['nullable'] ? "'nullable'" : "'required'";
            switch ($f['type']) {
                case 'text':
                case 'richtext':
                    $rules = [$required, "'string'"];
                    break;
                case 'integer':
                    $rules = [$required, "'integer'"];
                    break;
                case 'decimal':
                    // Cap the magnitude/scale to the column's decimal(p,s) so an over-long value can't be
                    // silently truncated by the database. The rule lives in src/ (FQ, no import needed).
                    $rules = [$required, "'numeric'", "new \\Ngos\\AdminCore\\Rules\\DecimalPrecision({$f['precision']}, {$f['scale']})"];
                    break;
                case 'boolean':
                    $rules = ["'nullable'", "'boolean'"];
                    break;
                case 'date':
                case 'datetime':
                    $rules = [$required, "'date'"];
                    break;
                case 'time':
                    // Accept both the form's H:i and the H:i:s a TIME column exports, so a CSV round-trips.
                    $rules = [$required, "'date_format:H:i,H:i:s'"];
                    break;
                case 'email':
                    $rules = [$required, "'email'", "'max:255'"];
                    break;
                case 'url':
                    $rules = [$required, "'url'", "'max:255'"];
                    break;
                case 'slug':
                    $rules = [$required, "'string'", "'max:255'", "'alpha_dash'"];
                    break;
                case 'json':
                    $rules = [$required, "'array'"]; // decoded from a JSON string in prepareForValidation()
                    break;
                case 'password':
                    $rules = [$update ? "'nullable'" : $required, "'string'", "'min:8'"];
                    break;
                case 'enum':
                    $rules = [$required, "\\Illuminate\\Validation\\Rule::enum(\\App\\Enums\\{$this->enumClass($f)}::class)"];
                    break;
                case 'image':
                    // Explicit allowlist — the bare `image` rule also accepts SVG (script-carrying) and bmp.
                    $rules = [$update ? "'nullable'" : $required, "'image'", "'mimes:jpg,jpeg,png,webp,gif'", "'max:2048'"];
                    break;
                case 'file':
                    // An explicit allowlist — never accept executable/markup uploads (php, phtml, svg, html…)
                    // onto the (public) upload disk. Widen this list per project if you need more types.
                    $rules = [$update ? "'nullable'" : $required, "'file'", "'max:10240'", "'mimes:pdf,doc,docx,xls,xlsx,csv,txt,zip'"];
                    break;
                case 'foreign':
                    $rules = [$required, "'integer'", "'exists:{$f['relTable']},id'"];
                    break;
                case 'belongsToMany':
                    $lines[] = "            '{$f['name']}' => ['array'],";
                    $lines[] = "            '{$f['name']}.*' => ['integer', 'exists:{$f['relTable']},id'],";
                    continue 2;
                case 'hasMany':
                    // Master-detail line items: a (possibly empty) array of rows, each with an optional id
                    // (existing row) plus the child's own fields. Tighten '{$f['name']}.*.<field>' per child.
                    $lines[] = "            '{$f['name']}' => ['nullable', 'array'],";
                    $lines[] = "            '{$f['name']}.*.id' => ['nullable'],";
                    continue 2;
                case 'translatable':
                    // Per-locale array (name[en], name[km], …). AutoTranslate fills the blanks before
                    // validation, so requiring the default locale is enough; the rest stay optional.
                    $tLocales = array_keys((array) config('admin-core.translation.locales', ['en' => 'English']));
                    $tDefault = (string) config('admin-core.translation.default', $tLocales[0] ?? 'en');
                    $lines[] = "            '{$f['name']}' => [{$required}, 'array'],";
                    foreach ($tLocales as $loc) {
                        $req = (! $f['nullable'] && $loc === $tDefault) ? "'required'" : "'nullable'";
                        $lines[] = "            '{$f['name']}.{$loc}' => [{$req}, 'string', 'max:255'],";
                    }
                    continue 2;
                default: // string
                    $rules = [$required, "'string'", "'max:255'"];
            }

            if ($f['unique']) {
                // Ignore self by the route-key column (uuid under hybrid, else id),
                // since the {id} route param is whatever the URL exposes. On a soft-deletes
                // resource, exclude trashed rows so a deleted value can be reused.
                $ignoreColumn = $this->uuid ? 'uuid' : 'id';
                $trashed = $this->softDeletes ? '->withoutTrashed()' : '';
                // Update uses the imported short `Rule` (see updateUses()); store has no import slot, so it
                // uses the fully-qualified name — same as the enum rule already does.
                $rules[] = $update
                    ? "Rule::unique('{$this->table}', '{$f['name']}')->ignore(\$this->route('id'), '{$ignoreColumn}'){$trashed}"
                    : ($this->softDeletes
                        ? "\\Illuminate\\Validation\\Rule::unique('{$this->table}', '{$f['name']}')->withoutTrashed()"
                        : "'unique:{$this->table},{$f['name']}'");
            }

            $lines[] = "            '{$f['name']}' => [" . implode(', ', $rules) . '],';
        }

        return implode("\n", $lines);
    }

    /**
     * The `prepareForValidation()` body lines, or '' when none apply. Pre-validation massaging:
     * a JSON column arrives as a textarea string and is decoded to an array; a richtext column is
     * HTML-sanitized; and a blank password on update is dropped so the existing hash isn't overwritten.
     */
    public function prepareBody(bool $update): string
    {
        $lines = [];
        foreach ($this->fields as $f) {
            if ($f['type'] === 'json') {
                $lines[] = "        if (is_string(\$this->{$f['name']})) {\n"
                    . "            \$this->merge(['{$f['name']}' => json_decode(\$this->{$f['name']}, true)]);\n"
                    . "        }";
            }
            if ($f['type'] === 'richtext') {
                // Sanitize WYSIWYG HTML on the way in (it's echoed raw on the show page).
                $lines[] = "        if (is_string(\$this->{$f['name']})) {\n"
                    . "            \$this->merge(['{$f['name']}' => \\Ngos\\AdminCore\\Support\\Html::clean(\$this->{$f['name']})]);\n"
                    . "        }";
            }
            if ($f['type'] === 'password' && $update) {
                $lines[] = "        if (blank(\$this->{$f['name']})) {\n"
                    . "            \$this->request->remove('{$f['name']}');\n"
                    . "        }";
            }
            if ($f['type'] === 'hasMany') {
                // The form owns the items block (marked by a hidden _<field>_form): drop fully-blank rows so
                // an empty submission validates as "no items" (and the service reads it as "remove all").
                $lines[] = "        if (\$this->boolean('_{$f['name']}_form')) {\n"
                    . "            \${$f['name']} = is_array(\$this->{$f['name']}) ? array_values(array_filter(\$this->{$f['name']}, fn (\$r) => is_array(\$r) && array_filter(\$r, fn (\$v) => \$v !== null && \$v !== '') !== [])) : [];\n"
                    . "            \$this->merge(['{$f['name']}' => \${$f['name']}]);\n"
                    . "        }";
            }
        }

        return implode("\n", $lines);
    }

    public function prepare(bool $update): string
    {
        $body = $this->prepareBody($update);

        if ($body === '') {
            return '';
        }

        return <<<PHP


    protected function prepareForValidation(): void
    {
$body
    }
PHP;
    }

    // ---- Form --------------------------------------------------------

    public function enctype(): string
    {
        return $this->hasFiles() ? ' enctype="multipart/form-data"' : '';
    }

    public function formFields(): string
    {
        $out = [];
        foreach ($this->fields as $f) {
            // foreign keys render a remote (searchable + paginated) select — no eager-load of the whole table.
            if ($f['type'] === 'belongsToMany') {
                $out[] = "@php(\${$f['relation']}Options = \\App\\Models\\{$f['relModel']}::orderBy('id')->get())";
                $out[] = "@php(\${$f['relation']}Selected = isset(\$object) ? \$object->{$f['relation']}->pluck('id')->all() : [])";
            }
        }
        foreach ($this->fields as $f) {
            if (! empty($f['system'])) {
                continue; // system fields are never rendered in the form
            }
            $out[] = $this->formField($f);
        }

        return implode("\n", $out);
    }

    private function formField(array $f): string
    {
        $col = $f['name'];
        // A translatable field renders the multi-locale widget (its own form-row), which posts
        // name[en], name[km], … and a _translate marker the AutoTranslate middleware fills.
        if ($f['type'] === 'translatable') {
            $tLabel = $this->label($col);

            return "<x-admin-core::translatable-input name=\"{$col}\" label=\"{$tLabel}\" :value=\"old('{$col}', \$object?->{$col} ?? [])\" />";
        }
        $label = $this->label(in_array($f['type'], ['foreign', 'belongsToMany'], true) ? $f['relation'] : $col);
        $old = "old('{$col}', \$object?->{$col})";
        // Write-once fields lock on edit (UX only — the real guard is the missing UpdateRequest rule).
        $ro = ! empty($f['writeOnce']) ? ' :readonly="(bool) $object"' : '';

        // Most controls are reusable components that render their own labelled row (label + control + error),
        // so styling lives in one place. Only the bespoke controls (boolean/image/file) wrap a raw control.
        switch ($f['type']) {
            case 'richtext':
                return "<x-admin-core::editor name=\"{$col}\" label=\"{$label}\" :value=\"{$old}\" />";
            case 'text':
                return "<x-admin-core::textarea name=\"{$col}\" label=\"{$label}\" :value=\"{$old}\"{$ro} />";
            case 'json':
                $jsonOld = "old('{$col}', is_array(\$object?->{$col}) ? json_encode(\$object->{$col}, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : \$object?->{$col})";

                return "<x-admin-core::textarea name=\"{$col}\" label=\"{$label}\" :value=\"{$jsonOld}\" rows=\"4\" class=\"font-monospace\" />";
            case 'enum':
                $enumClass = '\\App\\Enums\\' . $this->enumClass($f);
                $opts = "collect({$enumClass}::cases())->mapWithKeys(fn (\$case) => [\$case->value => \\Illuminate\\Support\\Str::headline(\$case->value)])";

                return "<x-admin-core::select name=\"{$col}\" label=\"{$label}\" :options=\"{$opts}\" :value=\"old('{$col}', \$object?->{$col}?->value)\" />";
            case 'foreign':
                // Searchable + paginated remote select. `source` resolves the related resource's `select` route
                // dynamically (falls back to a plain select if it has none). Only the current value is rendered —
                // the rest load on search, so the form never eager-loads the whole related table.
                $sel = "\$object?->{$f['relation']} ? [\$object->{$col} => ac_localize(\$object->{$f['relation']}->name)] : []";

                return "<x-admin-core::select name=\"{$col}\" label=\"{$label}\" source=\"{$f['relTable']}\" :options=\"{$sel}\" :value=\"{$old}\" placeholder=\"— search —\" />";
            case 'belongsToMany':
                return "<x-admin-core::select name=\"{$col}\" label=\"{$label}\" :options=\"\${$f['relation']}Options->mapWithKeys(fn (\$o) => [\$o->getKey() => ac_localize(\$o->name)])\" :value=\"old('{$col}', \${$f['relation']}Selected)\" multiple />";
            case 'hasMany':
                $hmRel = $f['relation'];
                $hmRows = "old('{$col}', \$object?->{$hmRel}->map(fn (\$i) => \$i->toArray())->all() ?? [])";
                $hmRow = "backend.pages.{$this->table}.partials.{$col}-row";

                return "<hr class=\"my-4\">\n"
                    . "<div class=\"d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2\">\n"
                    . "    <label class=\"form-label fw-semibold mb-0\">{$label}</label>\n"
                    . "    <small class=\"text-muted\">Line items — add a row per {$f['relModel']}.</small>\n"
                    . "</div>\n"
                    . "<input type=\"hidden\" name=\"_{$col}_form\" value=\"1\">\n"
                    . "<x-admin-core::repeater name=\"{$col}\" :rows=\"{$hmRows}\" row=\"{$hmRow}\" add-label=\"Add row\" />";
            // date/datetime use the date-input component (Air Datepicker via .js-datepicker); it formats a
            // Carbon value for the picker + 'date' rule and echoes a re-submitted string as-is.
            case 'date':
                return "<x-admin-core::date-input name=\"{$col}\" label=\"{$label}\" :value=\"old('{$col}', \$object?->{$col})\"{$ro} />";
            case 'datetime':
                return "<x-admin-core::date-input name=\"{$col}\" label=\"{$label}\" mode=\"datetime\" :value=\"old('{$col}', \$object?->{$col})\"{$ro} />";
            case 'password':
                return "<x-admin-core::input name=\"{$col}\" label=\"{$label}\" type=\"password\" autocomplete=\"new-password\" />";
            case 'integer':
                return "<x-admin-core::input name=\"{$col}\" label=\"{$label}\" type=\"number\" :value=\"{$old}\"{$ro} />";
            case 'decimal':
                return "<x-admin-core::input name=\"{$col}\" label=\"{$label}\" type=\"number\" step=\"0.01\" :value=\"{$old}\"{$ro} />";
            case 'email':
                return "<x-admin-core::input name=\"{$col}\" label=\"{$label}\" type=\"email\" :value=\"{$old}\"{$ro} />";
            case 'url':
                return "<x-admin-core::input name=\"{$col}\" label=\"{$label}\" type=\"url\" :value=\"{$old}\"{$ro} />";
            case 'time':
                return "<x-admin-core::input name=\"{$col}\" label=\"{$label}\" type=\"time\" :value=\"{$old}\"{$ro} />";
            case 'image':
                return "<x-admin-core::file-input name=\"{$col}\" label=\"{$label}\" image :value=\"\$object?->{$col}\" />";
            case 'file':
                return "<x-admin-core::file-input name=\"{$col}\" label=\"{$label}\" :value=\"\$object?->{$col}\" />";
            case 'boolean':
                return "<x-admin-core::checkbox name=\"{$col}\" label=\"{$label}\" :checked=\"{$old}\" />";
            default: // string, slug and any other text-like column
                return "<x-admin-core::input name=\"{$col}\" label=\"{$label}\" :value=\"{$old}\"{$ro} />";
        }
    }

    public function formScripts(): string
    {
        // Any <select> the form renders (enum, foreign, many-to-many) carries .admin-core-select,
        // so it's enhanced with select2 — one consistent dropdown style across every field type.
        if (! $this->hasForeign() && ! $this->hasManyToMany() && ! $this->hasEnum()) {
            return '';
        }

        return <<<'BLADE'

@push('scripts')
    <script>
        (function () {
            function acInitSelects(scope) {
                if (!window.jQuery || !$.fn.select2) return;
                // .not(.select2-hidden-accessible) keeps it idempotent (never double-enhance).
                $(scope).find('.admin-core-select').not('.select2-hidden-accessible')
                    .select2({theme: 'bootstrap-5', width: '100%'});
            }
            document.addEventListener('DOMContentLoaded', function () { acInitSelects(document); });
            // Enhance selects inside rows added dynamically by the repeater component.
            document.addEventListener('ac:repeater:added', function (e) { acInitSelects(e.target); });
        })();
    </script>
@endpush
BLADE;
    }

    // ---- Index table -------------------------------------------------

    /** Whether a field is shown in the index table / show view. Passwords are write-only. */
    public function isDisplayed(array $f): bool
    {
        // password is write-only; hasMany line items aren't a list/show column (shown via the form repeater).
        return ! in_array($f['type'], ['password', 'hasMany'], true);
    }

    public function thead(): string
    {
        $cells = ['    <th style="width:1%"><input type="checkbox" id="check-all"></th>'];
        foreach ($this->fields as $f) {
            if ($this->isDisplayed($f)) {
                $cells[] = $this->fieldTh($f);
            }
        }
        $cells[] = '    <th>Actions</th>';

        return implode("\n", $cells);
    }

    /** The `<th>` cell for one field (reused when inserting a column later). */
    public function fieldTh(array $f): string
    {
        return '    <th>' . $this->label(in_array($f['type'], ['foreign', 'belongsToMany'], true) ? $f['relation'] : $f['name']) . '</th>';
    }

    public function columnsJs(): string
    {
        // Checkbox carries the public route key (uuid under hybrid, else id) so
        // bulk-delete posts the same identifier the row URLs use.
        $pk = $this->uuid ? 'uuid' : 'id';
        $cols = ["                {data: '{$pk}', name: '{$pk}', orderable: false, searchable: false, className: 'text-center', render: (d) => '<input type=\"checkbox\" class=\"row-check\" value=\"' + d + '\">'},"];
        foreach ($this->fields as $f) {
            if ($this->isDisplayed($f)) {
                $cols[] = $this->fieldColumn($f);
            }
        }
        $cols[] = "                {data: 'actions', orderable: false, searchable: false},";

        return implode("\n", $cols);
    }

    /** The DataTables `columns:` entry for one field (reused when inserting a column later). */
    public function fieldColumn(array $f): string
    {
        // belongsTo: searchable + sortable by the related name (filterColumn + orderColumn below).
        if ($f['type'] === 'foreign') {
            return "                {data: '{$f['relation']}', name: '{$f['relation']}'},";
        }
        // belongsToMany: searchable by the related name (whereHas); not orderable — sorting a
        // multi-value relation is ambiguous.
        if ($f['type'] === 'belongsToMany') {
            return "                {data: '{$f['relation']}', name: '{$f['relation']}', orderable: false},";
        }
        if (in_array($f['type'], ['image', 'file'], true)) {
            return "                {data: '{$f['name']}', orderable: false, searchable: false},";
        }
        // Translatable JSON column: searchable (LIKE on the raw JSON) but not orderable.
        if ($f['type'] === 'translatable') {
            return "                {data: '{$f['name']}', name: '{$f['name']}', orderable: false},";
        }

        return "                {data: '{$f['name']}', name: '{$f['name']}'},";
    }

    /**
     * The same column list as columnsJs(), but as a PHP array literal for the data-driven
     * `<x-admin-core::data-table :columns=…>` (the shared datatable.js builds the DataTable from it).
     * The checkbox is the one client-rendered type; everything else is server-rendered.
     */
    public function columnsConfig(): string
    {
        $pk = $this->uuid ? 'uuid' : 'id';
        $cols = ["            ['type' => 'check', 'data' => '{$pk}'],"];
        foreach ($this->fields as $f) {
            if ($this->isDisplayed($f)) {
                $cols[] = $this->fieldColumnConfig($f);
            }
        }
        $cols[] = "            ['data' => 'actions', 'orderable' => false, 'searchable' => false],";

        return implode("\n", $cols);
    }

    /** One `:columns` array entry for a field — the PHP-array twin of fieldColumn(). */
    public function fieldColumnConfig(array $f): string
    {
        if ($f['type'] === 'foreign') {
            return "            ['data' => '{$f['relation']}', 'name' => '{$f['relation']}'],";
        }
        if ($f['type'] === 'belongsToMany') {
            return "            ['data' => '{$f['relation']}', 'name' => '{$f['relation']}', 'orderable' => false],";
        }
        if (in_array($f['type'], ['image', 'file'], true)) {
            return "            ['data' => '{$f['name']}', 'orderable' => false, 'searchable' => false],";
        }
        if ($f['type'] === 'translatable') {
            return "            ['data' => '{$f['name']}', 'name' => '{$f['name']}', 'orderable' => false],";
        }

        return "            ['data' => '{$f['name']}', 'name' => '{$f['name']}'],";
    }

    /** Parsed fields (read-only access for the add-field command). */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Segmented filter tabs for the first enum field (empty when there is none),
     * targeting its DataTable column (checkbox is column 0, then the fields).
     */
    public function filterTabs(string $tableId): string
    {
        // Count only the columns actually rendered before the enum (a write-only
        // password column is skipped, so a raw field-position index would be off by one).
        $index = 1; // first data column — the leading checkbox is column 0
        $enumClass = null;
        foreach ($this->fields as $f) {
            if ($f['type'] === 'enum') {
                $enumClass = $this->enumClass($f);
                break;
            }
            if ($this->isDisplayed($f)) {
                $index++;
            }
        }
        if ($enumClass === null) {
            return '';
        }

        // The component builds its tabs from the enum's cases — single source of truth.
        return "    <x-admin-core::filter-tabs table=\"#{$tableId}\" :column=\"{$index}\" :enum=\"\\App\\Enums\\{$enumClass}::class\" />\n\n";
    }

    // ---- Controller getData -----------------------------------------

    public function eager(): string
    {
        $relations = $this->apiWith();

        return $relations === '' ? '$relation' : "[{$relations}]";
    }

    /** Relation names (belongsTo + belongsToMany) as a quoted, comma-separated list for an array literal. */
    public function apiWith(): string
    {
        return collect($this->fields)
            ->whereIn('type', ['foreign', 'belongsToMany'])
            ->map(fn ($f) => "'{$f['relation']}'")
            ->implode(', ');
    }

    /**
     * Relation names appended to the CSV export as readable columns — belongsTo (the related name) and
     * belongsToMany (the related names joined). Eager-loaded by export() and rendered per type there.
     */
    public function exportRelations(): string
    {
        return collect($this->fields)
            ->whereIn('type', ['foreign', 'belongsToMany'])
            ->map(fn ($f) => "'{$f['relation']}'")
            ->implode(', ');
    }

    /**
     * Columns offered in the Export field-picker, as `value => Label`. The scalar columns a user cares
     * about (no password) plus the relation-name columns; id + timestamps round it out. The picker is
     * the menu — export() still whitelists whatever is actually requested.
     *
     * @return array<string, string>
     */
    public function exportFields(): array
    {
        $fields = ['id' => 'ID'];
        foreach ($this->fields as $f) {
            if ($f['type'] === 'belongsToMany') {
                $fields[$f['relation']] = $this->label($f['relation']); // e.g. tags (joined names)
                continue;
            }
            if (! $this->isColumn($f) || ! empty($f['system']) || $f['type'] === 'password') {
                continue; // skip non-columns, system fields and never-exported password columns
            }
            $fields[$f['name']] = $this->label($f['name']); // name, price, status, category_id, …
            if ($f['type'] === 'foreign') {
                $fields[$f['relation']] = $this->label($f['relation']); // category (related name)
            }
        }
        $fields['created_at'] = 'Created at';
        $fields['updated_at'] = 'Updated at';

        return $fields;
    }

    public function getDataColumns(): string
    {
        $lines = [];
        if ($this->hasName() && ! $this->nameIsTranslatable()) {
            $lines[] = "            ->editColumn('name', fn (\$row) => '<span class=\"text-capitalize\">' . e(\$row->name) . '</span>')";
        }
        foreach ($this->fields as $f) {
            if ($line = $this->fieldDataColumn($f)) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The getData() addColumn/editColumn line for one field, or null when the cell needs no
     * server-side render. Shared by the generator and `admin-core:field` so a field added later
     * renders in the list exactly like a generated one (Yes/No, formatted date, status badge, …).
     */
    public function fieldDataColumn(array $f): ?string
    {
        return match ($f['type']) {
            'foreign' => $this->foreignDataColumn($f),
            'belongsToMany' => "            ->addColumn('{$f['relation']}', fn (\$row) => \$row->{$f['relation']}->map(fn (\$i) => '<span class=\"badge text-bg-secondary\">' . e(ac_localize(\$i->name) ?: \$i->id) . '</span>')->implode(' '))\n"
                . "            ->filterColumn('{$f['relation']}', fn (\$q, \$keyword) => \$q->whereHas('{$f['relation']}', fn (\$rq) => \$rq->where('name', 'like', \"%{\$keyword}%\")))",
            // Match the show view's status badge / Yes-No / formatted date rather than leaking a raw value.
            'enum' => "            ->editColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']} ? '<span class=\"ac-status\" data-status=\"' . e(\$row->{$f['name']}->value) . '\">' . e(\\Illuminate\\Support\\Str::headline(\$row->{$f['name']}->value)) . '</span>' : '')",
            'boolean' => "            ->editColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']} ? '<span class=\"badge text-bg-success\">Yes</span>' : '<span class=\"badge text-bg-secondary\">No</span>')",
            'date' => "            ->editColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']}?->format('Y-m-d'))",
            'datetime' => "            ->editColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']}?->format('Y-m-d H:i'))",
            'image' => "            ->addColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']} ? '<img src=\"' . \\Ngos\\AdminCore\\Support\\Media::url(\$row->{$f['name']}) . '\" style=\"height:36px\" class=\"rounded\">' : '')",
            'file' => "            ->addColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']} ? '<a href=\"' . \\Ngos\\AdminCore\\Support\\Media::url(\$row->{$f['name']}) . '\" target=\"_blank\">file</a>' : '')",
            // Translatable JSON: show the current locale (fall back to the first filled one).
            'translatable' => "            ->editColumn('{$f['name']}', fn (\$row) => ac_localize(\$row->{$f['name']}))",
            // Rich text: strip HTML to a short plain-text preview in the list.
            'richtext' => "            ->editColumn('{$f['name']}', fn (\$row) => \\Illuminate\\Support\\Str::limit(strip_tags((string) \$row->{$f['name']}), 60))",
            default => null,
        };
    }

    /**
     * getData() lines for a belongsTo column: display the related name, make the global search match it
     * (filterColumn → whereHas on the related `name`), and make the column sortable by that name
     * (orderColumn → a correlated subquery). Assumes the related model has a `name` column — the same
     * assumption the form select and the list/show display already make.
     */
    private function foreignDataColumn(array $f): string
    {
        $rel = $f['relation'];
        $relModel = '\\App\\Models\\' . $f['relModel'];
        // The canonical relTable, computed once in parse(); re-deriving it here risked diverging from the
        // `exists:` rule for irregular plurals (e.g. the order subquery and the rule naming different tables).
        $relTable = $f['relTable'];

        return "            ->addColumn('{$rel}', fn (\$row) => ac_localize(\$row->{$rel}?->name))\n"
            . "            ->filterColumn('{$rel}', fn (\$q, \$keyword) => \$q->whereHas('{$rel}', fn (\$rq) => \$rq->where('name', 'like', \"%{\$keyword}%\")))\n"
            . "            ->orderColumn('{$rel}', fn (\$q, \$dir) => \$q->orderBy({$relModel}::select('name')->whereColumn('{$relTable}.id', '{$this->table}.{$f['name']}'), \$dir))";
    }

    /** Read-only detail rows for the show view. */
    public function showRows(): string
    {
        $rows = [];
        foreach ($this->fields as $f) {
            if (! $this->isDisplayed($f)) {
                continue; // password is write-only — never render its hash on the detail page
            }
            $label = $this->label(in_array($f['type'], ['foreign', 'belongsToMany'], true) ? $f['relation'] : $f['name']);
            $value = match ($f['type']) {
                'foreign' => "{{ ac_localize(\$object->{$f['relation']}?->name) }}",
                'belongsToMany' => "@foreach(\$object->{$f['relation']} as \$i)<x-admin-core::badge tone=\"secondary\">{{ ac_localize(\$i->name) ?: \$i->id }}</x-admin-core::badge> @endforeach",
                'image' => "@if(\$object->{$f['name']})<img src=\"{{ \\Ngos\\AdminCore\\Support\\Media::url(\$object->{$f['name']}) }}\" style=\"height:80px\" class=\"rounded\">@endif",
                'file' => "@if(\$object->{$f['name']})<a href=\"{{ \\Ngos\\AdminCore\\Support\\Media::url(\$object->{$f['name']}) }}\" target=\"_blank\">Download</a>@endif",
                'boolean' => "{{ \$object->{$f['name']} ? 'Yes' : 'No' }}",
                'enum' => "<x-admin-core::status :value=\"\$object->{$f['name']}\" />",
                'translatable' => "{{ ac_localize(\$object->{$f['name']}) }}",
                'richtext' => "{!! \$object->{$f['name']} !!}",
                default => "{{ \$object->{$f['name']} }}",
            };
            $rows[] = "            <x-admin-core::detail-row label=\"{$label}\">{$value}</x-admin-core::detail-row>";
        }

        return implode("\n", $rows);
    }

    public function rawColumns(): string
    {
        $raw = [];
        if ($this->hasName() && ! $this->nameIsTranslatable()) {
            $raw[] = "'name'";
        }
        foreach ($this->fields as $f) {
            if (in_array($f['type'], ['belongsToMany', 'image', 'file', 'enum', 'boolean'], true)) {
                $raw[] = "'{$f['name']}'";
            }
        }
        $raw[] = "'actions'";

        return implode(', ', $raw);
    }

    // ---- Service (uploads + m2m sync) --------------------------------

    public function serviceUses(): string
    {
        if (! $this->needsServiceBody()) {
            return '';
        }
        $uses = ['use Illuminate\Database\Eloquent\Model;'];
        if ($this->hasFiles()) {
            // File ops go through \Ngos\AdminCore\Support\Media (compress + disk/CDN), referenced
            // by FQCN in the generated body — so only UploadedFile needs importing here.
            $uses[] = 'use Illuminate\Http\UploadedFile;';
        }

        return implode("\n", $uses) . "\n";
    }

    public function serviceBody(): string
    {
        if (! $this->needsServiceBody()) {
            return '';
        }

        $extract = '';
        $sync = '';
        $syncMethods = '';
        foreach ($this->fields as $f) {
            if ($f['type'] === 'belongsToMany') {
                $extract .= "        \${$f['relation']} = \$data['{$f['name']}'] ?? [];\n        unset(\$data['{$f['name']}']);\n";
                $sync .= "\n        \$model->{$f['relation']}()->sync(\${$f['relation']});";
            }
            if ($f['type'] === 'hasMany') {
                $rel = $f['relation'];
                // null = the items block wasn't submitted (e.g. an API/import call) → leave children untouched.
                // Reconcile via BaseService::syncHasMany (update by id / create / delete the rest).
                $extract .= "        \${$rel} = \$data['{$f['name']}'] ?? null;\n        unset(\$data['{$f['name']}']);\n";
                $sync .= "\n        \$this->syncHasMany(\$model, '{$rel}', \${$rel});";
            }
        }

        $storeCreate = '';
        $storeUpdate = '';
        foreach ($this->fields as $f) {
            if (! $this->isFile($f)) {
                continue;
            }
            $c = $f['name'];
            $storeCreate .= "\n        if ((\$data['{$c}'] ?? null) instanceof UploadedFile) {\n            \$data['{$c}'] = \\Ngos\\AdminCore\\Support\\Media::store(\$data['{$c}'], '{$this->table}');\n        } else {\n            unset(\$data['{$c}']);\n        }";
            $storeUpdate .= "\n        if ((\$data['{$c}'] ?? null) instanceof UploadedFile) {\n            \\Ngos\\AdminCore\\Support\\Media::delete(\$model->{$c});\n            \$data['{$c}'] = \\Ngos\\AdminCore\\Support\\Media::store(\$data['{$c}'], '{$this->table}');\n        } else {\n            unset(\$data['{$c}']);\n        }";
        }

        $deleteBody = '';
        foreach ($this->fields as $f) {
            if ($this->isFile($f)) {
                $deleteBody .= "\n        \\Ngos\\AdminCore\\Support\\Media::delete(\$model->{$f['name']});";
            }
        }
        if ($deleteBody === '') {
            $delete = '';
        } elseif ($this->softDeletes) {
            // Soft-delete resource: drop the stored file only on a *permanent* (force) delete — so a
            // soft-deleted record keeps its file and can be restored intact. (find() reads only
            // non-trashed rows, hence findTrashed() here.)
            $delete = <<<PHP


    public function forceDelete(int|string \$id): void
    {
        \$model = \$this->findTrashed(\$id);{$deleteBody}
        \$model->forceDelete();
    }
PHP;
        } else {
            // No soft deletes: delete() is permanent, so clean up the file there.
            $delete = <<<PHP


    public function delete(int|string \$id): void
    {
        \$model = \$this->find(\$id);{$deleteBody}
        \$model->delete();
    }
PHP;
        }

        return <<<PHP


    public function create(array \$data): Model
    {
{$extract}{$storeCreate}
        \$model = \$this->model->create(\$data);{$sync}

        return \$model;
    }

    public function update(int|string \$id, array \$data): Model
    {
{$extract}        \$model = \$this->find(\$id);{$storeUpdate}
        \$model->update(\$data);{$sync}

        return \$model;
    }{$delete}{$syncMethods}
PHP;
    }
}
