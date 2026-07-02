<?php

namespace Ngos\AdminCore\Http\Controllers\Concerns;

use Ngos\AdminCore\States\Transition;

/**
 * Write-time authorization guards shared by the web and JSON-API surfaces, so the two enforce ONE permission
 * model (README: "the API and the back office enforce one permission model"). {@see WebController} defines the
 * same guards inline for the HTML channel; this trait carries them onto {@see ApiController} so a store/update/
 * destroy over JSON honours the identical field-level permissions, state-column protection and locked-state
 * refusal — not just the coarse resource-level permission the route middleware already checks.
 *
 * All hooks default to "no policy" (empty field permissions, no transitions, no locked states), so a resource
 * that declares none is unaffected — the guards are pure no-ops. A resource opts its API in by declaring the
 * SAME `fieldPermissions()` / `transitions()` / `$lockedStates` on its Api controller as on its web twin.
 */
trait GuardsResourceWrites
{
    /** The column that holds a document's state, for the transitions() state machine. */
    protected string $stateColumn = 'status';

    /**
     * States in which a record is read-only — update/destroy are refused (e.g. a posted invoice).
     *
     * @var array<int, string>
     */
    protected array $lockedStates = [];

    /**
     * Auth guard the field-permission check runs against. Null = the default guard; a portal API sets its
     * guard name so a token on a non-default guard is evaluated correctly.
     */
    protected ?string $apiGuard = null;

    /** The signed-in user on this controller's guard. */
    protected function policyUser()
    {
        return auth()->guard($this->apiGuard)->user();
    }

    /**
     * Map of field name => permission required to write it. Override to mirror the web controller's
     * fieldPermissions() so the API strips the same protected fields.
     *
     * @return array<string, string>
     */
    protected function fieldPermissions(): array
    {
        return [];
    }

    /**
     * The document lifecycle. Override to mirror the web controller's transitions() so the API refuses to set
     * the state column directly (it moves only through a transition's side-effect).
     *
     * @return array<int, Transition>
     */
    protected function transitions(): array
    {
        return [];
    }

    /**
     * Remove input the current user isn't allowed to write — the server-side guard, so even a hand-crafted
     * request can't set a protected field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripDeniedFields(array $data): array
    {
        foreach ($this->deniedFields() as $field) {
            unset($data[$field]);
        }

        return $data;
    }

    /**
     * The field names the current user may NOT write.
     *
     * @return array<int, string>
     */
    protected function deniedFields(): array
    {
        if (! config('admin-core.permission.enabled')) {
            return [];
        }

        $denied = [];
        foreach ($this->fieldPermissions() as $field => $permission) {
            if (! $this->policyUser()?->can($permission)) {
                $denied[] = $field;
            }
        }

        return $denied;
    }

    /** When the resource is a state machine, the state column moves only via transitions — never a plain write. */
    protected function stripStateColumn(array $data): array
    {
        if ($this->transitions() !== []) {
            unset($data[$this->stateColumn]);
        }

        return $data;
    }

    /** The record's current state as a plain string — unwraps a BackedEnum/UnitEnum (a generated status:enum cast). */
    protected function currentState($record): string
    {
        $raw = $record->{$this->stateColumn} ?? '';

        if ($raw instanceof \BackedEnum) {
            return (string) $raw->value;
        }

        if ($raw instanceof \UnitEnum) {
            return $raw->name;
        }

        return (string) $raw;
    }

    /** Is the record currently in a locked state? */
    protected function isLockedState($record): bool
    {
        return in_array($this->currentState($record), $this->lockedStates, true);
    }

    /** Refuse the write when the record sits in a locked state (a no-op unless $lockedStates is set). */
    protected function guardLocked(int|string $id): void
    {
        if ($this->lockedStates === []) {
            return;
        }

        if ($this->isLockedState($this->service->find($id))) {
            abort(403, __('admin-core::admin-core.states.locked'));
        }
    }
}
