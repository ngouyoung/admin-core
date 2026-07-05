<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * A singleton (one-record) admin screen — Settings, a company profile, the current user's profile. Unlike a
 * full CRUD resource it has no list / create / delete: just an **edit form** (index) and a **save** (update),
 * both gated by `edit-{resource}` and wired by `Route::crudSingleton('settings', SettingController::class)`.
 *
 * The single row is the one (or first) row, created on first save. For a per-owner singleton, override
 * {@see recordScope()} — the scope both resolves the row AND is force-re-applied after the form fills, so a
 * posted value can never hijack another owner's row (it works whether or not the scope column is fillable):
 *
 *   protected function recordScope(): array
 *   {
 *       return ['user_id' => auth()->id()];
 *   }
 */
abstract class SingletonController extends WebController
{
    /**
     * The attributes that identify the single record (the scope). Override for a per-owner singleton. Default []
     * = the one (or first) row. These keys are re-applied after the form fills, so `$data` can't hijack the row.
     *
     * @return array<string, mixed>
     */
    protected function recordScope(): array
    {
        return [];
    }

    /** The single record this screen edits — the scoped (or first) row, created on first save. */
    protected function record(): Model
    {
        return $this->service->query()->firstOrNew($this->recordScope());
    }

    /** Show the edit form for the single record. */
    public function index()
    {
        // Share the field-level deny list so <x-admin-core::field-guard> can lock fields the user can't write.
        view()->share('acDeniedFields', $this->deniedFields());

        return $this->view('edit', ['object' => $this->record()]);
    }

    /** Save the single record (inserting it the first time). The $id arg is unused — a singleton has no key. */
    public function update(int|string $id = 0): RedirectResponse
    {
        $record = $this->record();

        // Enforce the document state machine like WebController: a record in a locked state is read-only, and
        // the state column moves only through a transition (stripStateColumn below), never a plain form write.
        // No-ops unless the singleton declares transitions()/$lockedStates.
        if ($record->exists && $this->isLockedState($record)) {
            abort(403, __('admin-core::admin-core.states.locked'));
        }

        // Force the stored value of any field the user may not edit back into the request BEFORE validation, so
        // a `required` rule on a locked field still passes (mirrors WebController::update()). stripDeniedFields
        // then drops it from the write, leaving the value untouched.
        if (($denied = $this->deniedFields()) !== []) {
            request()->merge(collect($denied)->mapWithKeys(fn ($f) => [$f => $record->getRawOriginal($f)])->all());
        }

        $data = $this->stripStateColumn($this->stripDeniedFields(app($this->updateRequest)->validated()));
        $scope = $this->recordScope();

        // Re-resolve INSIDE the transaction (row-locked) so a row created since record() ran above is
        // UPDATED, not duplicated. forceFill($scope) AFTER fill($data) re-asserts the owner keys — a
        // tampered/omitted scope column can't repoint the row to another owner, and the scope is set even
        // when the column isn't fillable.
        $write = fn () => DB::transaction(function () use ($data, $scope) {
            $locked = $this->service->query()->lockForUpdate()->firstOrNew($scope);

            // Re-check the locked state on the ROW-LOCKED instance: the pre-transaction guard above is a
            // point-in-time read, so a concurrent transition could move the record into a locked state
            // between it and this write (e.g. while the FormRequest validated). Mirrors WebController's
            // guardedWrite(). No-op unless the singleton declares $lockedStates.
            if ($locked->exists && $this->isLockedState($locked)) {
                abort(403, __('admin-core::admin-core.states.locked'));
            }

            return $locked->fill($data)->forceFill($scope)->save();
        });

        if ($record->exists) {
            $write();
        } else {
            // The FIRST save inserts the row, and there may be no unique constraint on the scope — two
            // concurrent first saves (a double-submit) would both see "no row" and both INSERT. Serialize
            // them behind an atomic cache lock; the loser then finds the winner's row and updates it.
            try {
                cache()->lock('admin-core:singleton:' . md5(static::class . '|' . json_encode($scope)), 10)
                    ->block(5, $write);
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException|\BadMethodCallException) {
                $write(); // a stuck lock / lock-less cache store — still transactional + re-resolved
            }
        }

        return back()->with('success', $this->message('updated'));
    }
}
