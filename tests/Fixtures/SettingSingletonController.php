<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Http\Controllers\SingletonController;

/** A singleton screen over the widgets table (one row) — exercises SingletonController's index/update. */
class SettingSingletonController extends SingletonController
{
    public function __construct(WidgetService $service)
    {
        $this->viewPath = 'widgets.';
        $this->routeBase = 'settings.';
        $this->resource = 'setting';
        $this->service = $service;
        $this->updateRequest = StoreWidgetRequest::class; // validates name required
    }
}
