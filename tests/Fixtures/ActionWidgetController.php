<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Support\Collection;
use Ngos\AdminCore\Actions\Action;
use Ngos\AdminCore\Http\Controllers\WebController;

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
            Action::make('bulk-only')->onlyBulk()->withoutPermission()->handle(fn (Collection $records) => null),
            Action::make('row-only')->onlyOnRow()->withoutPermission()->handle(fn (Collection $records) => null),
        ];
    }

    protected function fieldPermissions(): array
    {
        return ['secret' => 'edit-secret-action-widget'];
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
