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
 *   - modifiers trailing  ?  = nullable,  ^  = unique   (e.g. slug:string^?)
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

    public function __construct(?string $raw)
    {
        $this->fields = $this->parse($raw);
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

    /** The primary-key line for the migration. */
    public function primaryKey(): string
    {
        return $this->uuid ? "\$table->uuid('id')->primary();" : '$table->id();';
    }

    /** The model's trait list (after `use `). */
    public function modelTraits(): string
    {
        $traits = ['HasFactory'];
        if ($this->uuid) {
            $traits[] = 'HasUuids';
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

            $nullable = false;
            $unique = false;
            while ($spec !== '' && in_array(substr($spec, -1), ['?', '^'], true)) {
                $spec[-1] === '?' ? $nullable = true : $unique = true;
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

            $fields[] = $this->field($name, $spec ?: 'string', $nullable, $unique, $enum);
        }

        return $fields ?: [$this->field('name', 'string')];
    }

    private function field(string $name, string $type, bool $nullable = false, bool $unique = false, array $enum = []): array
    {
        $f = compact('name', 'type', 'nullable', 'unique', 'enum');

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
        return collect($this->fields)->contains(fn ($f) => $f['name'] === 'name');
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
                'image', 'file' => "\$table->string('{$col}')",
                'foreign' => "\$table->" . ($this->uuid ? 'foreignUuid' : 'foreignId') . "('{$col}')" . ($n ? '->nullable()' : '') . '->constrained()' . ($n ? '->nullOnDelete()' : '->cascadeOnDelete()'),
                default => "\$table->string('{$col}')",
            };
            if ($f['type'] !== 'foreign') {
                // uploads are stored as a nullable path string regardless.
                if ($n || $this->isFile($f)) {
                    $line .= '->nullable()';
                }
                if ($f['unique']) {
                    $line .= '->unique()';
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
            $fk = $this->uuid ? 'foreignUuid' : 'foreignId';
            $blocks[] = <<<PHP

        Schema::create('{$pivot}', function (Blueprint \$table) {
            \$table->{$fk}('{$self}_id')->constrained()->cascadeOnDelete();
            \$table->{$fk}('{$other}_id')->constrained()->cascadeOnDelete();
        });
PHP;
        }

        return implode("\n", $blocks);
    }

    // ---- Model -------------------------------------------------------

    public function fillable(): string
    {
        return collect($this->fields)
            ->filter(fn ($f) => $this->isColumn($f))
            ->map(fn ($f) => "'{$f['name']}'")
            ->implode(', ');
    }

    public function modelUses(): string
    {
        $uses = [];
        if ($this->uuid) {
            $uses[] = 'use Illuminate\Database\Eloquent\Concerns\HasUuids;';
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

    /** Field-aware factory definition lines. */
    public function factoryDefinition(): string
    {
        $lines = [];
        foreach ($this->fields as $f) {
            if (! $this->isColumn($f)) {
                continue; // belongsToMany handled via relationships, not attributes
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
                $f['type'] === 'enum' => "fake()->randomElement(['" . implode("', '", $f['enum']) . "'])",
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
                case 'email':
                    $rules = [$required, "'email'", "'max:255'"];
                    break;
                case 'enum':
                    $rules = [$required, "'in:" . implode(',', $f['enum']) . "'"];
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
                $rules[] = $update
                    ? "Rule::unique('{$this->table}', '{$f['name']}')->ignore(\$this->route('id'))"
                    : "'unique:{$this->table},{$f['name']}'";
            }

            $lines[] = "            '{$f['name']}' => [" . implode(', ', $rules) . '],';
        }

        return implode("\n", $lines);
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

        $control = match ($f['type']) {
            'text' => "<textarea name=\"{$col}\" id=\"{$col}\" rows=\"3\" class=\"form-control {$err}\">{{ {$old} }}</textarea>",
            'boolean' => "<div class=\"form-check\">\n            <input type=\"checkbox\" name=\"{$col}\" id=\"{$col}\" value=\"1\" class=\"form-check-input {$err}\" {{ {$old} ? 'checked' : '' }}>\n        </div>",
            'integer' => "<input type=\"number\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\">",
            'decimal' => "<input type=\"number\" step=\"0.01\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\">",
            'date' => "<input type=\"date\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\">",
            'datetime' => "<input type=\"datetime-local\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\">",
            'email' => "<input type=\"email\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\">",
            'enum' => $this->enumSelect($f, $err, $old),
            'image' => $this->fileInput($f, $err, true),
            'file' => $this->fileInput($f, $err, false),
            'foreign' => $this->foreignSelect($f, $err, $old),
            'belongsToMany' => $this->manySelect($f, $err),
            default => "<input type=\"text\" name=\"{$col}\" id=\"{$col}\" class=\"form-control {$err}\" value=\"{{ {$old} }}\">",
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
        $options = '';
        foreach ($f['enum'] as $value) {
            $options .= "\n            <option value=\"{$value}\" @selected({$old} === '{$value}')>{$value}</option>";
        }

        return "<select name=\"{$f['name']}\" id=\"{$f['name']}\" class=\"form-select {$err}\">{$options}\n        </select>";
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

    public function thead(): string
    {
        $cells = ['    <th style="width:1%"><input type="checkbox" id="check-all"></th>'];
        foreach ($this->fields as $f) {
            $cells[] = '    <th>' . $this->label(in_array($f['type'], ['foreign', 'belongsToMany'], true) ? $f['relation'] : $f['name']) . '</th>';
        }
        $cells[] = '    <th>Actions</th>';

        return implode("\n", $cells);
    }

    /** Per-column search inputs in the table footer (text fields only). */
    public function tfoot(): string
    {
        $cells = ['    <th></th>'];
        foreach ($this->fields as $f) {
            if (in_array($f['type'], ['foreign', 'belongsToMany', 'image', 'file', 'boolean'], true)) {
                $cells[] = '    <th></th>';
            } else {
                $cells[] = '    <th><input type="text" class="form-control form-control-sm column-search" placeholder="Search ' . $this->label($f['name']) . '"></th>';
            }
        }
        $cells[] = '    <th></th>';

        return implode("\n", $cells);
    }

    public function columnsJs(): string
    {
        $cols = ["                {data: 'id', name: 'id', orderable: false, searchable: false, className: 'text-center', render: (d) => '<input type=\"checkbox\" class=\"row-check\" value=\"' + d + '\">'},"];
        foreach ($this->fields as $f) {
            if (in_array($f['type'], ['foreign', 'belongsToMany', 'image', 'file'], true)) {
                $key = in_array($f['type'], ['foreign', 'belongsToMany'], true) ? $f['relation'] : $f['name'];
                $cols[] = "                {data: '{$key}', orderable: false, searchable: false},";
            } else {
                $cols[] = "                {data: '{$f['name']}', name: '{$f['name']}'},";
            }
        }
        $cols[] = "                {data: 'actions', orderable: false, searchable: false},";

        return implode("\n", $cols);
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
            if ($f['type'] === 'foreign') {
                $lines[] = "            ->addColumn('{$f['relation']}', fn (\$row) => \$row->{$f['relation']}?->name)";
            }
            if ($f['type'] === 'belongsToMany') {
                $lines[] = "            ->addColumn('{$f['relation']}', fn (\$row) => \$row->{$f['relation']}->map(fn (\$i) => '<span class=\"badge text-bg-secondary\">' . e(\$i->name ?? \$i->id) . '</span>')->implode(' '))";
            }
            if ($f['type'] === 'image') {
                $lines[] = "            ->addColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']} ? '<img src=\"' . asset('storage/' . \$row->{$f['name']}) . '\" style=\"height:36px\" class=\"rounded\">' : '')";
            }
            if ($f['type'] === 'file') {
                $lines[] = "            ->addColumn('{$f['name']}', fn (\$row) => \$row->{$f['name']} ? '<a href=\"' . asset('storage/' . \$row->{$f['name']}) . '\" target=\"_blank\">file</a>' : '')";
            }
        }

        return implode("\n", $lines);
    }

    /** Read-only detail rows for the show view. */
    public function showRows(): string
    {
        $rows = [];
        foreach ($this->fields as $f) {
            $label = $this->label(in_array($f['type'], ['foreign', 'belongsToMany'], true) ? $f['relation'] : $f['name']);
            $value = match ($f['type']) {
                'foreign' => "{{ \$object->{$f['relation']}?->name }}",
                'belongsToMany' => "@foreach(\$object->{$f['relation']} as \$i)<span class=\"badge text-bg-secondary\">{{ \$i->name ?? \$i->id }}</span> @endforeach",
                'image' => "@if(\$object->{$f['name']})<img src=\"{{ asset('storage/' . \$object->{$f['name']}) }}\" style=\"height:80px\" class=\"rounded\">@endif",
                'file' => "@if(\$object->{$f['name']})<a href=\"{{ asset('storage/' . \$object->{$f['name']}) }}\" target=\"_blank\">Download</a>@endif",
                'boolean' => "{{ \$object->{$f['name']} ? 'Yes' : 'No' }}",
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
            if (in_array($f['type'], ['belongsToMany', 'image', 'file'], true)) {
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
        $delete = $deleteBody === '' ? '' : <<<PHP


    public function delete(int|string \$id): void
    {
        \$model = \$this->find(\$id);{$deleteBody}
        \$model->delete();
    }
PHP;

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
