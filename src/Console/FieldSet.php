<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Support\Str;

/**
 * Parses the `--fields` DSL and builds every code snippet the generator needs.
 *
 * DSL:  "name:string, price:decimal?, status:enum:draft|published,
 *        category_id:foreign, avatar:image?, brochure:file?, tags:belongsToMany"
 *   - scalar   string text integer decimal boolean date datetime email
 *   - enum     values piped:  status:enum:draft|published|archived
 *   - foreign  column ending in _id:  category_id:foreign  (-> belongsTo)
 *   - image    file upload, stored on the public disk, thumbnailed in the table
 *   - file     any file upload
 *   - belongsToMany (aliases manyToMany, m2m)  ->  pivot table + multi-select + sync
 *   - modifiers trailing  ?  = nullable,  ^  = unique,  #  = index   (e.g. slug:string^?, status:enum:a|b#)
 */
class FieldSet
{
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

            if (in_array($spec, ['manytomany', 'm2m'], true)) {
                $spec = 'belongsToMany';
            }

            $fields[] = $this->field($name, $spec ?: 'string', $nullable, $unique, $enum, $writeOnce, $system, $index);
        }

        return $fields ?: [$this->field('name', 'string')];
    }

    private function field(string $name, string $type, bool $nullable = false, bool $unique = false, array $enum = [], bool $writeOnce = false, bool $system = false, bool $index = false): array
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
            $f['relModel'] = Str::studly(Str::singular($base));
            $f['relTable'] = Str::snake(Str::pluralStudly($base));
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
        return $f['type'] !== 'belongsToMany';
    }

    private function isFile(array $f): bool
    {
        return in_array($f['type'], ['image', 'file'], true);
    }

    private function hasName(): bool
    {
        return $this->hasNameOverride ?? collect($this->fields)->contains(fn ($f) => $f['name'] === 'name');
    }

    private function hasFiles(): bool
    {
        return collect($this->fields)->contains(fn ($f) => $this->isFile($f));
    }

    private function hasManyToMany(): bool
    {
        return collect($this->fields)->contains(fn ($f) => $f['type'] === 'belongsToMany');
    }

    private function hasForeign(): bool
    {
        return collect($this->fields)->contains(fn ($f) => $f['type'] === 'foreign');
    }

    private function needsServiceBody(): bool
    {
        return $this->hasFiles() || $this->hasManyToMany();
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
                'text' => "\$table->text('{$col}')",
                'integer' => "\$table->integer('{$col}')",
                'decimal' => "\$table->decimal('{$col}', 10, 2)",
                'boolean' => "\$table->boolean('{$col}')" . ($n ? '' : '->default(false)'),
                'date' => "\$table->date('{$col}')",
                'datetime' => "\$table->dateTime('{$col}')",
                'time' => "\$table->time('{$col}')",
                'json' => "\$table->json('{$col}')",
                'image', 'file' => "\$table->string('{$col}')",
                'foreign' => "\$table->foreignId('{$col}')" . ($n ? '->nullable()' : '') . '->constrained()' . ($n ? '->nullOnDelete()' : '->cascadeOnDelete()'),
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
            // A blank slug derives from the `name` field when one exists.
            if ($f['type'] === 'slug' && $this->hasName()) {
                $assigns[] = "                \$model->{$f['name']} ??= \\Illuminate\\Support\\Str::slug(\$model->name);";
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
                $lines[] = "            '{$f['relation']}' => \$this->{$f['relation']}?->name,";
                continue;
            }
            if ($f['type'] === 'belongsToMany') {
                $lines[] = "            '{$f['relation']}' => \$this->whenLoaded('{$f['relation']}', fn () => \$this->{$f['relation']}->pluck('name')),";
                continue;
            }
            if (in_array($f['type'], ['image', 'file'], true)) {
                $lines[] = "            '{$f['name']}' => \$this->{$f['name']} ? asset('storage/' . \$this->{$f['name']}) : null,";
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
                $f['name'] === 'name' => 'fake()->name()',
                $f['name'] === 'email' || $f['type'] === 'email' => 'fake()->safeEmail()',
                $f['type'] === 'text' => 'fake()->paragraph()',
                $f['type'] === 'integer' => 'fake()->numberBetween(1, 1000)',
                $f['type'] === 'decimal' => 'fake()->randomFloat(2, 1, 1000)',
                $f['type'] === 'boolean' => 'fake()->boolean()',
                $f['type'] === 'date' => 'fake()->date()',
                $f['type'] === 'datetime' => 'fake()->dateTime()',
                $f['type'] === 'time' => "fake()->time('H:i')",
                $f['type'] === 'url' => 'fake()->url()',
                $f['type'] === 'slug' => 'fake()->unique()->slug()',
                $f['type'] === 'json' => "['key' => fake()->word()]",
                $f['type'] === 'password' => "'password'", // hashed by the model's 'hashed' cast
                $f['type'] === 'enum' => "fake()->randomElement(\\App\\Enums\\{$this->enumClass($f)}::cases())",
                $f['type'] === 'foreign' => "\\App\\Models\\{$f['relModel']}::factory()",
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
            'decimal' => "'decimal:2'",
            'json' => "'array'",
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
                    $rules = [$required, "'string'"];
                    break;
                case 'integer':
                    $rules = [$required, "'integer'"];
                    break;
                case 'decimal':
                    $rules = [$required, "'numeric'"];
                    break;
                case 'boolean':
                    $rules = ["'nullable'", "'boolean'"];
                    break;
                case 'date':
                case 'datetime':
                    $rules = [$required, "'date'"];
                    break;
                case 'time':
                    $rules = [$required, "'date_format:H:i'"];
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
                    $rules = [$update ? "'nullable'" : $required, "'image'", "'max:2048'"];
                    break;
                case 'file':
                    $rules = [$update ? "'nullable'" : $required, "'file'", "'max:10240'"];
                    break;
                case 'foreign':
                    $rules = [$required, "'integer'", "'exists:{$f['relTable']},id'"];
                    break;
                case 'belongsToMany':
                    $lines[] = "            '{$f['name']}' => ['array'],";
                    $lines[] = "            '{$f['name']}.*' => ['integer', 'exists:{$f['relTable']},id'],";
                    continue 2;
                default: // string
                    $rules = [$required, "'string'", "'max:255'"];
            }

            if ($f['unique']) {
                // Ignore self by the route-key column (uuid under hybrid, else id),
                // since the {id} route param is whatever the URL exposes.
                $ignoreColumn = $this->uuid ? 'uuid' : 'id';
                $rules[] = $update
                    ? "Rule::unique('{$this->table}', '{$f['name']}')->ignore(\$this->route('id'), '{$ignoreColumn}')"
                    : "'unique:{$this->table},{$f['name']}'";
            }

            $lines[] = "            '{$f['name']}' => [" . implode(', ', $rules) . '],';
        }

        return implode("\n", $lines);
    }

    /**
     * A `prepareForValidation()` body when fields need pre-validation massaging:
     * a JSON column arrives as a textarea string and is decoded to an array (so
     * the `array` cast stores it once), and a blank password on update is dropped
     * so the existing hash isn't overwritten. Empty (omitted) when neither applies.
     */
    /** The `prepareForValidation()` body lines (json decode / blank-password drop), or '' when none apply. */
    public function prepareBody(bool $update): string
    {
        $lines = [];
        foreach ($this->fields as $f) {
            if ($f['type'] === 'json') {
                $lines[] = "        if (is_string(\$this->{$f['name']})) {\n"
                    . "            \$this->merge(['{$f['name']}' => json_decode(\$this->{$f['name']}, true)]);\n"
                    . "        }";
            }
            if ($f['type'] === 'password' && $update) {
                $lines[] = "        if (blank(\$this->{$f['name']})) {\n"
                    . "            \$this->request->remove('{$f['name']}');\n"
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
            if ($f['type'] === 'foreign') {
                $out[] = "@php(\${$f['relation']}Options = \\App\\Models\\{$f['relModel']}::orderBy('id')->get())";
            }
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
        $label = $this->label(in_array($f['type'], ['foreign', 'belongsToMany'], true) ? $f['relation'] : $col);
        $err = "@error('{$col}') is-invalid @enderror";
        $old = "old('{$col}', \$object?->{$col})";
        // Write-once fields lock on edit (UX only — the real guard is the missing UpdateRequest rule).
        $ro = ! empty($f['writeOnce']) ? " {{ \$object ? 'readonly' : '' }}" : '';

        $control = match ($f['type']) {
            'text' => "<textarea name=\"{$col}\" id=\"{$col}\" rows=\"3\" class=\"form-control {$err}\"{$ro}>{{ {$old} }}</textarea>",
            // The hidden 0 makes an *unchecked* box still submit the field (browsers send nothing for
            // an unchecked checkbox) — otherwise you could never turn a boolean off on edit. The checkbox
            // comes after it with the same name, so when checked its "1" wins.
            'boolean' => "<input type=\"hidden\" name=\"{$col}\" value=\"0\">\n        <div class=\"form-check\">\n            <input type=\"checkbox\" name=\"{$col}\" id=\"{$col}\" value=\"1\" class=\"form-check-input {$err}\" {{ {$old} ? 'checked' : '' }}>\n        </div>",
            'integer' => "<input type=\"number\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\"{$ro}>",
            'decimal' => "<input type=\"number\" step=\"0.01\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\"{$ro}>",
            // Format the Carbon value to the exact shape each HTML input parses (Y-m-d / Y-m-d\TH:i) —
            // its default __toString ("Y-m-d H:i:s") is rejected, so the value wouldn't load on edit.
            // old() (after a validation error) already holds the correctly-shaped submitted string.
            'date' => "<input type=\"date\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ old('{$col}', \$object?->{$col}?->format('Y-m-d')) }}\"{$ro}>",
            'datetime' => "<input type=\"datetime-local\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ old('{$col}', \$object?->{$col}?->format('Y-m-d\\TH:i')) }}\"{$ro}>",
            'time' => "<input type=\"time\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\"{$ro}>",
            'email' => "<input type=\"email\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\"{$ro}>",
            'url' => "<input type=\"url\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\"{$ro}>",
            'password' => "<input type=\"password\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" autocomplete=\"new-password\">",
            'json' => "<textarea name=\"{$col}\" id=\"{$col}\" rows=\"4\" class=\"form-control font-monospace {$err}\"{$ro}>{{ is_array({$old}) ? json_encode({$old}, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : {$old} }}</textarea>",
            'enum' => $this->enumSelect($f, $err, $old),
            'image' => $this->fileInput($f, $err, true),
            'file' => $this->fileInput($f, $err, false),
            'foreign' => $this->foreignSelect($f, $err, $old),
            'belongsToMany' => $this->manySelect($f, $err),
            default => "<input type=\"text\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\"{$ro}>",
        };

        return <<<BLADE
<div class="row mb-3">
    <label for="{$col}" class="col-md-2 col-sm-3 col-4 col-form-label text-end">{$label}:</label>
    <div class="col-md-8 col-sm-8 col-8">
        {$control}
        @error('{$col}')<div class="invalid-feedback d-block">{{ \$message }}</div>@enderror
    </div>
</div>
BLADE;
    }

    private function enumSelect(array $f, string $err, string $old): string
    {
        // Single source of truth: iterate the backed enum's cases (compare by ->value,
        // since the cast makes $object->{field} an enum instance).
        $enumClass = '\\App\\Enums\\' . $this->enumClass($f);
        $selected = "old('{$f['name']}', \$object?->{$f['name']}?->value)";

        return "<select name=\"{$f['name']}\" id=\"{$f['name']}\" class=\"form-select {$err}\">\n"
            . "            @foreach ({$enumClass}::cases() as \$case)\n"
            . "                <option value=\"{{ \$case->value }}\" @selected({$selected} === \$case->value)>{{ \\Illuminate\\Support\\Str::headline(\$case->value) }}</option>\n"
            . "            @endforeach\n        </select>";
    }

    private function fileInput(array $f, string $err, bool $image): string
    {
        $col = $f['name'];
        $input = "<input type=\"file\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\"" . ($image ? ' accept="image/*"' : '') . '>';
        if ($image) {
            $input .= "\n        @if(isset(\$object) && \$object->{$col})<img src=\"{{ asset('storage/' . \$object->{$col}) }}\" class=\"mt-2 rounded\" style=\"height:60px\">@endif";
        } elseif (true) {
            $input .= "\n        @if(isset(\$object) && \$object->{$col})<a href=\"{{ asset('storage/' . \$object->{$col}) }}\" target=\"_blank\" class=\"d-block mt-1 small\">current file</a>@endif";
        }

        return $input;
    }

    private function foreignSelect(array $f, string $err, string $old): string
    {
        $var = $f['relation'] . 'Options';

        return "<select name=\"{$f['name']}\" id=\"{$f['name']}\" class=\"form-select admin-core-select {$err}\">\n"
            . "            <option value=\"\">— select —</option>\n"
            . "            @foreach(\${$var} as \$opt)\n"
            . "                <option value=\"{{ \$opt->id }}\" @selected({$old} == \$opt->id)>{{ \$opt->name ?? \$opt->id }}</option>\n"
            . "            @endforeach\n"
            . '        </select>';
    }

    private function manySelect(array $f, string $err): string
    {
        $var = $f['relation'] . 'Options';
        $sel = $f['relation'] . 'Selected';

        return "<select name=\"{$f['name']}[]\" id=\"{$f['name']}\" multiple class=\"form-select admin-core-select {$err}\">\n"
            . "            @foreach(\${$var} as \$opt)\n"
            . "                <option value=\"{{ \$opt->id }}\" @selected(in_array(\$opt->id, old('{$f['name']}', \${$sel})))>{{ \$opt->name ?? \$opt->id }}</option>\n"
            . "            @endforeach\n"
            . '        </select>';
    }

    public function formScripts(): string
    {
        if (! $this->hasForeign() && ! $this->hasManyToMany()) {
            return '';
        }

        return <<<'BLADE'

@push('scripts')
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            if (window.jQuery && $.fn.select2) {
                $('.admin-core-select').select2({theme: 'bootstrap-5', width: '100%'});
            }
        });
    </script>
@endpush
BLADE;
    }

    // ---- Index table -------------------------------------------------

    /** Whether a field is shown in the index table / show view. Passwords are write-only. */
    public function isDisplayed(array $f): bool
    {
        return $f['type'] !== 'password';
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
        // A belongsTo column carries a `name`, so it's searchable + orderable via the
        // filterColumn/orderColumn (whereHas / correlated subquery on the related `name`).
        if ($f['type'] === 'foreign') {
            return "                {data: '{$f['relation']}', name: '{$f['relation']}'},";
        }
        if (in_array($f['type'], ['belongsToMany', 'image', 'file'], true)) {
            $key = $f['type'] === 'belongsToMany' ? $f['relation'] : $f['name'];

            return "                {data: '{$key}', orderable: false, searchable: false},";
        }

        return "                {data: '{$f['name']}', name: '{$f['name']}'},";
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
        $relations = collect($this->fields)
            ->whereIn('type', ['foreign', 'belongsToMany'])
            ->map(fn ($f) => "'{$f['relation']}'")
            ->implode(', ');

        return $relations === '' ? '$relation' : "[{$relations}]";
    }

    public function getDataColumns(): string
    {
        $lines = [];
        if ($this->hasName()) {
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
            'belongsToMany' => "            ->addColumn('{$f['relation']}', fn (\$row) => \$row->{$f['relation']}->map(fn (\$i) => '<span class=\"badge text-bg-secondary\">' . e(\$i->name ?? \$i->id) . '</span>')->implode(' '))",
            // Match the show view's status badge / Yes-No / formatted date rather than leaking a raw value.
            'enum' => "            ->editColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']} ? '<span class=\"ac-status\" data-status=\"' . e(\$row->{$f['name']}->value) . '\">' . e(\\Illuminate\\Support\\Str::headline(\$row->{$f['name']}->value)) . '</span>' : '')",
            'boolean' => "            ->editColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']} ? '<span class=\"badge text-bg-success\">Yes</span>' : '<span class=\"badge text-bg-secondary\">No</span>')",
            'date' => "            ->editColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']}?->format('Y-m-d'))",
            'datetime' => "            ->editColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']}?->format('Y-m-d H:i'))",
            'image' => "            ->addColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']} ? '<img src=\"' . asset('storage/' . \$row->{$f['name']}) . '\" style=\"height:36px\" class=\"rounded\">' : '')",
            'file' => "            ->addColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']} ? '<a href=\"' . asset('storage/' . \$row->{$f['name']}) . '\" target=\"_blank\">file</a>' : '')",
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
        $relTable = Str::plural(Str::snake($f['relModel']));

        return "            ->addColumn('{$rel}', fn (\$row) => \$row->{$rel}?->name)\n"
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
                'foreign' => "{{ \$object->{$f['relation']}?->name }}",
                'belongsToMany' => "@foreach(\$object->{$f['relation']} as \$i)<span class=\"badge text-bg-secondary\">{{ \$i->name ?? \$i->id }}</span> @endforeach",
                'image' => "@if(\$object->{$f['name']})<img src=\"{{ asset('storage/' . \$object->{$f['name']}) }}\" style=\"height:80px\" class=\"rounded\">@endif",
                'file' => "@if(\$object->{$f['name']})<a href=\"{{ asset('storage/' . \$object->{$f['name']}) }}\" target=\"_blank\">Download</a>@endif",
                'boolean' => "{{ \$object->{$f['name']} ? 'Yes' : 'No' }}",
                'enum' => "@if(\$object->{$f['name']})<span class=\"ac-status\" data-status=\"{{ \$object->{$f['name']}->value }}\">{{ \\Illuminate\\Support\\Str::headline(\$object->{$f['name']}->value) }}</span>@endif",
                default => "{{ \$object->{$f['name']} }}",
            };
            $rows[] = "            <tr>\n                <th style=\"width:220px\">{$label}</th>\n                <td>{$value}</td>\n            </tr>";
        }

        return implode("\n", $rows);
    }

    public function rawColumns(): string
    {
        $raw = [];
        if ($this->hasName()) {
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
            $uses[] = 'use Illuminate\Http\UploadedFile;';
            $uses[] = 'use Illuminate\Support\Facades\Storage;';
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
        foreach ($this->fields as $f) {
            if ($f['type'] === 'belongsToMany') {
                $extract .= "        \${$f['relation']} = \$data['{$f['name']}'] ?? [];\n        unset(\$data['{$f['name']}']);\n";
                $sync .= "\n        \$model->{$f['relation']}()->sync(\${$f['relation']});";
            }
        }

        $storeCreate = '';
        $storeUpdate = '';
        foreach ($this->fields as $f) {
            if (! $this->isFile($f)) {
                continue;
            }
            $c = $f['name'];
            $storeCreate .= "\n        if ((\$data['{$c}'] ?? null) instanceof UploadedFile) {\n            \$data['{$c}'] = \$data['{$c}']->store('{$this->table}', 'public');\n        } else {\n            unset(\$data['{$c}']);\n        }";
            $storeUpdate .= "\n        if ((\$data['{$c}'] ?? null) instanceof UploadedFile) {\n            if (\$model->{$c}) {\n                Storage::disk('public')->delete(\$model->{$c});\n            }\n            \$data['{$c}'] = \$data['{$c}']->store('{$this->table}', 'public');\n        } else {\n            unset(\$data['{$c}']);\n        }";
        }

        $deleteBody = '';
        foreach ($this->fields as $f) {
            if ($this->isFile($f)) {
                $deleteBody .= "\n        if (\$model->{$f['name']}) {\n            Storage::disk('public')->delete(\$model->{$f['name']});\n        }";
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
    }{$delete}
PHP;
    }
}
