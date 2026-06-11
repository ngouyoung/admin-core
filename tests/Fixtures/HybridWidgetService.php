<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Services\BaseService;

class HybridWidgetService extends BaseService
{
    public function __construct(HybridWidget $model)
    {
        $this->model = $model;
    }
}
