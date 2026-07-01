<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Support\Collection;
use Ngos\AdminCore\Actions\Action;
use Ngos\AdminCore\Http\Controllers\WebController;
use Ngos\AdminCore\States\Transition;

/**
 * Exercises declarative table actions + field-level permissions. The exposed* methods surface the protected
 * resolution helpers so the tests can assert what the UI / endpoint would see.
 */
class ActionWidgetController extends WebController
{
    public function __construct(WidgetService $service)
    {
        $this->viewPath = 'widgets.';
        $this->routeBase = 'actionWidgets.';
        $this->resource = 'action-widget';
        $this->service = $service;
        $this->storeRequest = ActionWidgetRequest::class;
        $this->updateRequest = ActionWidgetRequest::class;
    }

    protected function resourceActions(): array
    {
        return [
            Action::make('publish')->label('Publish')->icon('bi bi-send')->color('success')->confirm()
                ->handle(fn (Collection $records) => $records->each->update(['status' => 'published'])),
            Action::make('archive')->permission('archive-action-widget')
                ->handle(fn (Collection $records) => $records->each->update(['status' => 'archived'])),
            Action::make('count')->withoutPermission()
                ->handle(fn (Collection $records) => ['message' => $records->count() . ' counted']),
            Action::make('refund')->requiresApproval()
                ->handle(fn (Collection $records) => $records->each->update(['status' => 'refunded'])),
            Action::make('bulk-only')->onlyBulk()->withoutPermission()->handle(fn (Collection $records) => null),
            Action::make('row-only')->onlyOnRow()->withoutPermission()->handle(fn (Collection $records) => null),
        ];
    }

    protected function fieldPermissions(): array
    {
        return ['secret' => 'edit-secret-action-widget'];
    }

    protected array $lockedStates = ['posted'];

    protected function transitions(): array
    {
        return [
            Transition::make('confirm')->from('draft')->to('confirmed'),
            Transition::make('post')->from('confirmed')->to('posted')->confirm()
                ->handle(fn ($record) => $record->photo = 'posted-marker'), // observable side-effect
            Transition::make('cancel')->fromAny()->to('cancelled')->withoutPermission(),
            Transition::make('reopen')->from('cancelled')->to('draft')
                ->guard(fn ($record) => $record->name !== 'locked-reopen'), // a vetoing guard

            // A state transition that COLLECTS validated input — the validated values reach the handler.
            Transition::make('close')->from('confirmed')->to('closed')->withoutPermission()
                ->form(['counted' => ['required', 'numeric', 'min:0'], 'note' => ['nullable', 'string']])
                ->handle(fn ($record, array $input) => $record->photo = 'counted:' . $input['counted'] . '|note:' . ($input['note'] ?? '')),

            // A PURE action (no ->to()) — runs a side-effect without moving state, idempotent via the submit token.
            Transition::make('pay-in')->fromAny()->withoutPermission()
                ->guard(fn ($record) => $record->name !== 'no-payin') // a vetoing guard, for the release test
                ->form(['amount' => ['required', 'numeric', 'min:1']])
                ->handle(fn ($record, array $input) => $record->sort = (int) $record->sort + (int) $input['amount']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function exposedTransitions(Widget $record): array
    {
        return $this->transitionsFor($record);
    }

    public function getData($relation = null)
    {
        return parent::getData($relation)->make(true);
    }

    /** @return array<int, array<string, mixed>> */
    public function exposedActionsConfig(): array
    {
        return $this->actionsConfig();
    }

    /** @return array<int, array<string, mixed>> */
    public function exposedRowActions(Widget $model): array
    {
        return $this->rowActionsFor($model);
    }

    /** @return array<int, string> */
    public function exposedDeniedFields(): array
    {
        return $this->deniedFields();
    }
}
