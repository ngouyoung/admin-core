<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Http\Controllers\SingletonController;
use Ngos\AdminCore\States\Transition;

/**
 * A singleton screen that ALSO declares a document state machine (transitions + locked states) — to prove
 * SingletonController::update() enforces the state machine like WebController does.
 */
class StateSingletonController extends SingletonController
{
    protected array $lockedStates = ['locked'];

    public function __construct(WidgetService $service)
    {
        $this->viewPath = 'widgets.';
        $this->routeBase = 'stateSetting.';
        $this->resource = 'state-setting';
        $this->service = $service;
        $this->updateRequest = ActionWidgetRequest::class; // validates name; permits a status field
    }

    protected function transitions(): array
    {
        return [Transition::make('lock')->from('open')->to('locked')];
    }
}
