<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Http\Controllers\ApiController;

class WidgetApiController extends ApiController
{
    protected array $searchable = ['name'];

    protected array $sortable = ['name', 'created_at'];

    protected array $filterable = ['status'];

    public function __construct(WidgetService $service)
    {
        $this->service = $service;
        $this->resource = WidgetResource::class;
        $this->storeRequest = StoreWidgetRequest::class;
        $this->updateRequest = UpdateWidgetRequest::class;
    }
}
