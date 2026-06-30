<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Http\Controllers\WebController;

class WidgetController extends WebController
{
    public function __construct(WidgetService $service)
    {
        $this->viewPath = 'widgets.';
        $this->routeBase = 'widgets.';
        $this->service = $service;
        $this->storeRequest = StoreWidgetRequest::class;
        $this->updateRequest = UpdateWidgetRequest::class;
    }

    public function getData($relation = null)
    {
        return parent::getData($relation)->make(true);
    }

    /** A select filter on `status` (exact) + a date-range filter on `created_at` — the whitelist for getData. */
    protected function listFilters(): array
    {
        return [
            ['column' => 'status', 'type' => 'select', 'label' => 'Status'],
            ['column' => 'created_at', 'type' => 'date', 'label' => 'Created'],
        ];
    }
}
