<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Http\Controllers\ApiController;
use Ngos\AdminCore\States\Transition;

/**
 * A JSON-API controller that declares the SAME write policy as a web twin would: a field-level permission on
 * `secret`, a state machine (so the `status` column is transition-only), and a locked `posted` state. Exercises
 * that ApiController now enforces field-permission stripping, state-column stripping and locked-state refusal.
 */
class PolicyWidgetApiController extends ApiController
{
    protected array $lockedStates = ['posted'];

    public function __construct(WidgetService $service)
    {
        $this->service = $service;
        $this->resource = WidgetResource::class;
        $this->storeRequest = ActionWidgetRequest::class;
        $this->updateRequest = ActionWidgetRequest::class;
    }

    protected function fieldPermissions(): array
    {
        return ['secret' => 'edit-secret-widget'];
    }

    protected function transitions(): array
    {
        return [Transition::make('post')->from('draft')->to('posted')];
    }
}
