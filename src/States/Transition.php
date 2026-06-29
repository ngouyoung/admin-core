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
 */
class Transition
{
    protected ?string $label = null;

    protected ?string $icon = null;

    protected string $color = 'secondary';

    /** @var array<int, string> source states ('*' = any) */
    protected array $from = [];

    protected string $to = '';

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

    public function to(string $state): static
    {
        $this->to = $state;

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

    public function toState(): string
    {
        return $this->to;
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

    /** Run the side-effect (if any) over the record. */
    public function run(Model $record): void
    {
        if ($this->handler !== null) {
            ($this->handler)($record);
        }
    }

    /**
     * Serialise for the show-page buttons.
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
        ];
    }
}
