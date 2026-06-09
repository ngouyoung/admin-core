<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Http\Controllers\CrudController;

class WidgetController extends CrudController
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
}
