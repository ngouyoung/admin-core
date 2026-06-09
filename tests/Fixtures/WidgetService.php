<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Ngos\AdminCore\Services\CrudService;

class WidgetService extends CrudService
{
    public function __construct(Widget $widget)
    {
        $this->model = $widget;
    }
}
