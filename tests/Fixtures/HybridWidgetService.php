<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Services\CrudService;

class HybridWidgetService extends CrudService
{
    public function __construct(HybridWidget $model)
    {
        $this->model = $model;
    }
}
