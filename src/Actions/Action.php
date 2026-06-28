<?php

namespace Ngos\AdminCore\Actions;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A declarative table action — a button that runs a closure over the selected row(s).
 *
 * One declaration drives BOTH the bulk-toolbar button and the per-row kebab item; the package wires the
 * route, the permission gate (server-enforced), the confirm dialog and the success toast. Declared in a
 * controller's resourceActions():
 *
 *   Action::make('mark-paid')->label('Mark as paid')->icon('bi bi-cash')->color('success')
 *       ->confirm()->handle(fn ($records) => $records->each->update(['status' => 'paid']))
 *
 * The handler receives an Eloquent Collection of the affected models (already scoped to the resource query,
 * so global scopes / soft-deletes / tenancy still apply — a user can only act on rows they can see). It may
 * return ['message' => '…'] to override the toast text, or nothing.
 */
class Action
{
    protected ?string $label = null;

    protected ?string $icon = null;

    /** A Bootstrap colour variant (success, danger, warning, …) for the button. */
    protected string $color = 'secondary';

    /** Explicit permission name; null = derive from the resource + key. */
    protected ?string $permission = null;

    /** false = no permission required (anyone who can reach the list may run it). */
    protected bool $gated = true;

    protected bool $needsConfirm = false;

    protected ?string $confirmText = null;

    protected bool $onRow = true;

    protected bool $onBulk = true;

    protected ?Closure $handler = null;

    protected ?string $successMessage = null;

    protected bool $requiresApproval = false;

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

    /** Require an explicit permission to run (instead of the derived {key}-{resource}). */
    public function permission(string $permission): static
    {
        $this->permission = $permission;
        $this->gated = true;

        return $this;
    }

    /** Open the action to anyone who can reach the list — no extra permission. */
    public function withoutPermission(): static
    {
        $this->gated = false;

        return $this;
    }

    /** Ask for a SweetAlert confirmation before running (optionally with custom text). */
    public function confirm(?string $text = null): static
    {
        $this->needsConfirm = true;
        $this->confirmText = $text;

        return $this;
    }

    /** Offer the action only as a bulk action (not in the per-row menu). */
    public function onlyBulk(): static
    {
        $this->onRow = false;

        return $this;
    }

    /** Offer the action only in the per-row menu (not as a bulk action). */
    public function onlyOnRow(): static
    {
        $this->onBulk = false;

        return $this;
    }

    public function handle(Closure $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    /** Override the success toast text. */
    public function success(string $message): static
    {
        $this->successMessage = $message;

        return $this;
    }

    /**
     * Hold the action for approval: a user who may run it but not approve it (lacks `approve-{key}-{resource}`)
     * files a pending request instead of executing; an approver runs it from the inbox. A user who CAN approve
     * runs it directly. No-op when permissions are disabled (there's no approver concept then).
     *
     * The approve permission is always derived as `approve-{key}-{resource}` — it does NOT follow an explicit
     * ->permission() override (that only changes the permission to REQUEST the action).
     */
    public function requiresApproval(bool $requires = true): static
    {
        $this->requiresApproval = $requires;

        return $this;
    }

    public function needsApproval(): bool
    {
        return $this->requiresApproval;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isBulk(): bool
    {
        return $this->onBulk;
    }

    public function isRow(): bool
    {
        return $this->onRow;
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

    /**
     * The permission this action requires, or null when ungated. Defaults to the resource permission pattern
     * with the action key (e.g. 'mark-paid-order'); falls back to just the key when no resource is known.
     */
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

    /** The confirm prompt, or null when no confirmation is required. */
    public function resolveConfirm(): ?string
    {
        if (! $this->needsConfirm) {
            return null;
        }

        return $this->confirmText ?? __('admin-core::admin-core.confirm.run_action');
    }

    /** The success toast text, with :count replaced by the number of affected rows. */
    public function resolveSuccess(int $affected): string
    {
        if ($this->successMessage !== null) {
            return $this->successMessage;
        }

        return str_replace(':count', (string) $affected, __('admin-core::admin-core.toast.action_done'));
    }

    /**
     * Run the action over the affected records.
     *
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $records
     * @return array{message?: string}|null
     */
    public function run(Collection $records): ?array
    {
        if ($this->handler === null) {
            return null;
        }

        $result = ($this->handler)($records);

        return is_array($result) ? $result : null;
    }

    /**
     * Serialise for the front-end datatable config (a bulk button). $url is this action's endpoint.
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
