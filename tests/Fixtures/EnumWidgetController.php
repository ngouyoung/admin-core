<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Http\Controllers\WebController;
use Ngos\AdminCore\States\Transition;

/**
 * A state machine over a model whose `status` is ENUM-CAST (status:enum). Drives the re-fetching sites the
 * unwrap fix touches: runTransition() (HTTP POST) and isLockedState() (edit/delete lock). 'posted' is locked.
 */
class EnumWidgetController extends WebController
{
    public function __construct(EnumWidgetService $service)
    {
        $this->viewPath = 'widgets.';
        $this->routeBase = 'enumWidgets.';
        $this->resource = 'enum-widget';
        $this->service = $service;
        $this->storeRequest = ActionWidgetRequest::class;
        $this->updateRequest = ActionWidgetRequest::class;
    }

    protected array $lockedStates = ['posted'];

    protected function transitions(): array
    {
        return [
            Transition::make('confirm')->from('draft')->to('confirmed')->withoutPermission(),
            Transition::make('post')->from('confirmed')->to('posted')->withoutPermission(),
        ];
    }
}
