<?php

namespace Ngos\AdminCore\States;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A state transition for a document-style resource — a button that moves one record from one state to another,
 * optionally running a side-effect (e.g. posting stock movements) atomically. Declared in a controller's
 * transitions():
 *
 *   Transition::make('post')->from('confirmed')->to('posted')->confirm()
 *       ->handle(fn ($record) => $record->postToStock())
 *
 * The package wires the route, the permission gate (`{key}-{resource}`, server-enforced), the confirm dialog,
 * and the atomic state change (lock → verify the `from` state → run the handler → set the `to` state, all in
 * one transaction, so a double-click can't run the side-effect twice). A record in a locked state can't be
 * edited or deleted.
 *
 * An action can also collect VALIDATED INPUT — declare a `form()` and the validated values reach the handler's
 * second argument (the show page auto-renders a modal); and `to(null)` runs a side-effect WITHOUT moving state
 * (a pure action — e.g. a cash pay-in), kept idempotent by the form's one-time submit token:
 *
 *   Transition::make('close')->from('open')->to('closed')
 *       ->form(['closing_counted' => ['required', 'numeric', 'min:0'], 'note' => ['nullable', 'string']])
 *       ->handle(fn ($record, array $input) => app(ShiftService::class)->close($record, $input))
 */
class Transition
{
    protected ?string $label = null;

    protected ?string $icon = null;

    protected string $color = 'secondary';

    /** @var array<int, string> source states ('*' = any) */
    protected array $from = [];

    /** Target state, or null for a pure action that runs a side-effect without moving state. */
    protected ?string $to = null;

    /** @var array<string, mixed>|Closure|null  field => rules (or a rich descriptor); null = no input form */
    protected $form = null;

    protected ?string $permission = null;

    protected bool $gated = true;

    protected bool $needsConfirm = false;

    protected ?string $confirmText = null;

    protected ?Closure $guard = null;

    protected ?Closure $handler = null;

    final public function __construct(protected string $key) {}

    public static function make(string $key): static
    {
        return new static($key);
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /** @param  string|array<int, string>  $states */
    public function from(string|array $states): static
    {
        $this->from = array_values((array) $states);

        return $this;
    }

    /** Allow the transition from any state. */
    public function fromAny(): static
    {
        $this->from = ['*'];

        return $this;
    }

    /** The target state. Pass null (or never call this) for a pure action that doesn't move state. */
    public function to(?string $state): static
    {
        $this->to = $state;

        return $this;
    }

    /**
     * Collect validated input before running — `field => rules`, or the rich form
     * `field => ['rules' => [...], 'type' => 'number|text|textarea|select|date|checkbox', 'label' => '...',
     * 'options' => [...]]`. The validated values are passed to the handler's second argument, and the show page
     * renders a modal from this schema. A Closure schema is re-evaluated on each render + POST (no memoisation),
     * so keep it cheap — resolve a small options list, not an expensive query per call.
     *
     * @param  array<string, mixed>|Closure  $schema
     */
    public function form(array|Closure $schema): static
    {
        $this->form = $schema;

        return $this;
    }

    public function permission(string $permission): static
    {
        $this->permission = $permission;
        $this->gated = true;

        return $this;
    }

    public function withoutPermission(): static
    {
        $this->gated = false;

        return $this;
    }

    public function confirm(?string $text = null): static
    {
        $this->needsConfirm = true;
        $this->confirmText = $text;

        return $this;
    }

    /** A last-second veto run against the (locked) record — return false to block the transition. */
    public function guard(Closure $guard): static
    {
        $this->guard = $guard;

        return $this;
    }

    /** The side-effect to run as part of the transition (e.g. write stock movements). */
    public function handle(Closure $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function toState(): ?string
    {
        return $this->to;
    }

    /** Does this transition move the state column? (False = a pure side-effect action.) */
    public function movesState(): bool
    {
        return $this->to !== null && $this->to !== '';
    }

    public function hasForm(): bool
    {
        return $this->form !== null;
    }

    /**
     * The validation rules for the input form, `field => rules`.
     *
     * @return array<string, mixed>
     */
    public function formRules(): array
    {
        $rules = [];
        foreach ($this->resolveForm() as $field => $def) {
            $rules[$field] = is_array($def) && array_key_exists('rules', $def) ? $def['rules'] : $def;
        }

        return $rules;
    }

    /**
     * Field descriptors for the auto-rendered modal: name, label, type, options, required.
     *
     * @return array<int, array<string, mixed>>
     */
    public function formFields(): array
    {
        $fields = [];
        foreach ($this->resolveForm() as $field => $def) {
            $rich = is_array($def) && array_key_exists('rules', $def);
            $rules = $rich ? $def['rules'] : $def;
            $flat = is_array($rules) ? $rules : explode('|', (string) $rules);

            $fields[] = [
                'name' => $field,
                'label' => ($rich ? ($def['label'] ?? null) : null) ?? Str::headline($field),
                'type' => ($rich ? ($def['type'] ?? null) : null) ?? $this->inferType($flat),
                'options' => $rich ? ($def['options'] ?? []) : [],
                'required' => $this->flatHas($flat, 'required'),
            ];
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function resolveForm(): array
    {
        $form = $this->form instanceof Closure ? ($this->form)() : $this->form;

        return is_array($form) ? $form : [];
    }

    /** @param  array<int, mixed>  $rules */
    private function inferType(array $rules): string
    {
        if ($this->flatHas($rules, 'numeric') || $this->flatHas($rules, 'integer')) {
            return 'number';
        }
        if ($this->flatHas($rules, 'boolean')) {
            return 'checkbox';
        }
        if ($this->flatHas($rules, 'date')) {
            return 'date';
        }

        return 'text';
    }

    /** Whether a (flat) rules list contains a rule (matching its bare name, e.g. "min:0" matches "min"). */
    private function flatHas(array $rules, string $name): bool
    {
        foreach ($rules as $rule) {
            $bare = is_string($rule) ? strtolower(explode(':', $rule, 2)[0]) : '';
            if ($bare === $name) {
                return true;
            }
        }

        return false;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function resolveLabel(): string
    {
        return $this->label ?? Str::headline($this->key);
    }

    /** Is this transition available from the given current state? */
    public function appliesTo(string $current): bool
    {
        return in_array('*', $this->from, true) || in_array($current, $this->from, true);
    }

    /** The permission required, or null when ungated. Defaults to `{key}-{resource}`. */
    public function resolvePermission(string $resource = ''): ?string
    {
        if (! $this->gated) {
            return null;
        }

        if ($this->permission !== null) {
            return $this->permission;
        }

        if ($resource === '') {
            return $this->key;
        }

        return str_replace(
            ['{action}', '{resource}'],
            [$this->key, $resource],
            (string) config('admin-core.permission.pattern', '{action}-{resource}')
        );
    }

    public function resolveConfirm(): ?string
    {
        if (! $this->needsConfirm) {
            return null;
        }

        return $this->confirmText ?? __('admin-core::admin-core.states.confirm');
    }

    public function passesGuard(Model $record): bool
    {
        return $this->guard === null || (bool) ($this->guard)($record);
    }

    /**
     * Run the side-effect (if any) over the record, passing the validated form input. A handler declared with a
     * single parameter (`fn ($record) => …`) simply ignores the second argument — backward-compatible.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(Model $record, array $input = []): void
    {
        if ($this->handler !== null) {
            ($this->handler)($record, $input);
        }
    }

    /**
     * Serialise for the show-page buttons (+ the modal when the action collects input).
     *
     * @return array<string, mixed>
     */
    public function toArray(string $url): array
    {
        return [
            'key' => $this->key,
            'label' => $this->resolveLabel(),
            'icon' => $this->icon,
            'color' => $this->color,
            'url' => $url,
            'confirm' => $this->resolveConfirm(),
            'form' => $this->hasForm() ? $this->formFields() : null,
        ];
    }
}
