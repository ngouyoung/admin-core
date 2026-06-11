<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Services\BaseService;

class WidgetService extends BaseService
{
    public function __construct(Widget $widget)
    {
        $this->model = $widget;
    }
}
