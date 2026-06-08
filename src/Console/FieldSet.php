<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Support\Str;

/**
 * Parses the `--fields` DSL and builds every code snippet the generator needs.
 *
 * DSL:  "name:string, price:decimal?, status:enum:draft|published, category_id:foreign"
 *   - type      one of: string text integer decimal boolean date datetime email enum foreign
 *   - enum      values piped:  status:enum:draft|published|archived
 *   - foreign   column ending in _id:  category_id:foreign  (-> belongsTo Category)
 *   - modifiers trailing  ?  = nullable,  ^  = unique   (e.g. slug:string^?)
 */
class FieldSet
{
    /** @var array<int, array<string, mixed>> */
    private array $fields;

    public function __construct(?string $raw)
    {
        $this->fields = $this->parse($raw);
    }

    private function parse(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [['name' => 'name', 'type' => 'string', 'nullable' => false, 'unique' => false]];
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
                $enum = array_filter(array_map('trim', explode('|', substr($spec, 5))));
                $spec = 'enum';
            }

            $field = [
                'name' => $name,
                'type' => $spec ?: 'string',
                'nullable' => $nullable,
                'unique' => $unique,
                'enum' => $enum,
            ];

            if ($field['type'] === 'foreign') {
                $base = Str::beforeLast($name, '_id');
                $field['relation'] = Str::camel($base);
                $field['relModel'] = Str::studly(Str::singular($base));
                $field['relTable'] = Str::snake(Str::pluralStudly($base));
            }

            $fields[] = $field;
        }

        return $fields ?: [['name' => 'name', 'type' => 'string', 'nullable' => false, 'unique' => false]];
    }

    public function hasForeign(): bool
    {
        return collect($this->fields)->contains(fn ($f) => $f['type'] === 'foreign');
    }

    private function hasName(): bool
    {
        return collect($this->fields)->contains(fn ($f) => $f['name'] === 'name');
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
            $col = $f['name'];
            $n = $f['nullable'];
            $u = $f['unique'];
            $line = match ($f['type']) {
                'text' => "\$table->text('{$col}')",
                'integer' => "\$table->integer('{$col}')",
                'decimal' => "\$table->decimal('{$col}', 10, 2)",
                'boolean' => "\$table->boolean('{$col}')" . ($n ? '' : '->default(false)'),
                'date' => "\$table->date('{$col}')",
                'datetime' => "\$table->dateTime('{$col}')",
                'foreign' => "\$table->foreignId('{$col}')" . ($n ? '->nullable()' : '') . '->constrained()' . ($n ? '->nullOnDelete()' : '->cascadeOnDelete()'),
                default => "\$table->string('{$col}')", // string, email, enum
            };
            if ($f['type'] !== 'foreign') {
                if ($n) {
                    $line .= '->nullable()';
                }
                if ($u) {
                    $line .= '->unique()';
                }
            }
            $lines[] = '            ' . $line . ';';
        }

        return implode("\n", $lines);
    }

    // ---- Model -------------------------------------------------------

    public function fillable(): string
    {
        return collect($this->fields)->map(fn ($f) => "'{$f['name']}'")->implode(', ');
    }

    public function modelUses(): string
    {
        return $this->hasForeign()
            ? "use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;\n"
            : '';
    }

    public function relations(): string
    {
        $methods = [];
        foreach ($this->fields as $f) {
            if ($f['type'] !== 'foreign') {
                continue;
            }
            $methods[] = <<<PHP

    public function {$f['relation']}(): BelongsTo
    {
        return \$this->belongsTo(\\App\\Models\\{$f['relModel']}::class);
    }
PHP;
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
            $rules = [$f['nullable'] ? "'nullable'" : "'required'"];
            switch ($f['type']) {
                case 'text':
                    $rules[] = "'string'";
                    break;
                case 'integer':
                    $rules[] = "'integer'";
                    break;
                case 'decimal':
                    $rules[] = "'numeric'";
                    break;
                case 'boolean':
                    $rules = ["'nullable'", "'boolean'"];
                    break;
                case 'date':
                case 'datetime':
                    $rules[] = "'date'";
                    break;
                case 'email':
                    $rules[] = "'email'";
                    $rules[] = "'max:255'";
                    break;
                case 'enum':
                    $rules[] = "'in:" . implode(',', $f['enum']) . "'";
                    break;
                case 'foreign':
                    $rules[] = "'integer'";
                    $rules[] = "'exists:{$f['relTable']},id'";
                    break;
                default: // string
                    $rules[] = "'string'";
                    $rules[] = "'max:255'";
            }

            if ($f['unique']) {
                if ($update) {
                    $rules[] = "Rule::unique('{$this->table()}', '{$f['name']}')->ignore(\$this->route('id'))";
                } else {
                    $rules[] = "'unique:{$this->table()},{$f['name']}'";
                }
            }

            $lines[] = "            '{$f['name']}' => [" . implode(', ', $rules) . '],';
        }

        return implode("\n", $lines);
    }

    /** Resolved from the resource name by the command via setTable(). */
    private string $table = 'dummyModels';

    public function setTable(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    private function table(): string
    {
        return $this->table;
    }

    // ---- Form --------------------------------------------------------

    public function formFields(): string
    {
        $out = [];

        foreach ($this->fields as $f) {
            if ($f['type'] === 'foreign') {
                $var = $f['relation'] . 'Options';
                $out[] = "@php(\${$var} = \\App\\Models\\{$f['relModel']}::orderBy('id')->get())";
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
        $label = $this->label($f['type'] === 'foreign' ? $f['relation'] : $col);
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
            'foreign' => $this->foreignSelect($f, $err, $old),
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

    public function formScripts(): string
    {
        if (! $this->hasForeign()) {
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
        $cells = [];
        foreach ($this->fields as $f) {
            $cells[] = '    <th>' . $this->label($f['type'] === 'foreign' ? $f['relation'] : $f['name']) . '</th>';
        }
        $cells[] = '    <th>Actions</th>';

        return implode("\n", $cells);
    }

    public function columnsJs(): string
    {
        $cols = [];
        foreach ($this->fields as $f) {
            if ($f['type'] === 'foreign') {
                $cols[] = "                {data: '{$f['relation']}', name: '{$f['relation']}', orderable: false, searchable: false},";
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
            ->where('type', 'foreign')
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
        }

        return implode("\n", $lines);
    }

    public function rawColumns(): string
    {
        $raw = ["'actions'"];
        if ($this->hasName()) {
            array_unshift($raw, "'name'");
        }

        return implode(', ', $raw);
    }
}
