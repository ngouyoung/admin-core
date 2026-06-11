<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Http\Controllers\CrudController;

class HybridWidgetController extends CrudController
{
    public function __construct(HybridWidgetService $service)
    {
        $this->viewPath = 'hybrid_widgets.';
        $this->routeBase = 'hybrid_widgets.';
        $this->service = $service;
        $this->storeRequest = StoreWidgetRequest::class;
        $this->updateRequest = UpdateWidgetRequest::class;
    }

    public function getData($relation = null)
    {
        return parent::getData($relation)->make(true);
    }
}
