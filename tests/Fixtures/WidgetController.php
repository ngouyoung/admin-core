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

    /** The filter whitelist for getData: one of every type (select / date / text / number / money number). */
    protected function listFilters(): array
    {
        return [
            ['column' => 'status', 'type' => 'select', 'label' => 'Status'],
            ['column' => 'created_at', 'type' => 'date', 'label' => 'Created'],
            ['column' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['column' => 'sort', 'type' => 'number', 'label' => 'Sort'],
            ['column' => 'price', 'type' => 'number', 'label' => 'Price', 'money' => true, 'currency' => 'KHR'],
            // A foreign-style select whose options are a CLOSURE that throws if evaluated — getData must never
            // run it (it reads type/value only); the options query belongs to the rendered bar, not the data
            // endpoint. (No test sends filter[category_id], so the column not existing never matters.)
            ['column' => 'category_id', 'type' => 'select', 'label' => 'Category',
                'options' => fn () => throw new \RuntimeException('foreign options query ran on getData')],
        ];
    }
}
