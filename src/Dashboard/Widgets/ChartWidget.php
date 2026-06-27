<?php

namespace Ngos\AdminCore\Dashboard\Widgets;

use Ngos\AdminCore\Dashboard\DashboardContext;
use Ngos\AdminCore\Dashboard\Widget;

/**
 * An ApexCharts chart. Implement chart() to return ['type' => 'line|bar|area|pie|donut', 'series' => [...],
 * 'categories' => [...]] for the active range — the package renders it via the lazy-loaded ApexCharts bundle.
 */
abstract class ChartWidget extends Widget
{
    public function type(): string
    {
        return 'chart';
    }

    public function colSpan(): int
    {
        return 6;
    }

    /** @return array<string,mixed> ApexCharts config: type, series, categories, ... */
    abstract public function chart(DashboardContext $context): array;

    final public function data(DashboardContext $context): array
    {
        return [
            'title' => $this->title(),
            'key' => $this->key(),
            'chart' => $this->chart($context),
        ];
    }
}
