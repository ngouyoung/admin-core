<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Services\BaseService;

class EnumWidgetService extends BaseService
{
    public function __construct(EnumWidget $widget)
    {
        $this->model = $widget;
    }
}
