<?php

namespace Ngos\AdminCore\Dashboard\Widgets;

use Ngos\AdminCore\Dashboard\Concerns\ComputesTrend;
use Ngos\AdminCore\Dashboard\DashboardContext;
use Ngos\AdminCore\Dashboard\Widget;

/**
 * A KPI card: one metric for the active range, an optional trend arrow vs the previous period, and an
 * optional drill-down link. Subclass and implement value(); override previousValue() to get the trend.
 */
abstract class StatWidget extends Widget
{
    use ComputesTrend;

    public function type(): string
    {
        return 'stat';
    }

    /** The metric for the active range. */
    abstract public function value(DashboardContext $context): float|int|string;

    /** The same metric for the previous period — return null to hide the trend. */
    public function previousValue(DashboardContext $context): float|int|null
    {
        return null;
    }

    /** A drill-down URL (e.g. the filtered list), or null. */
    public function link(): ?string
    {
        return null;
    }

    /** Format the raw value for display (override for currency, etc.). */
    public function format(float|int|string $value): string
    {
        return is_numeric($value) ? number_format((float) $value) : (string) $value;
    }

    /** Icon accent tone, 1-4. */
    public function tone(): string
    {
        return '1';
    }

    final public function data(DashboardContext $context): array
    {
        $value = $this->value($context);

        return [
            'title' => $this->title(),
            'value' => $this->format($value),
            'icon' => $this->icon(),
            'link' => $this->link(),
            'tone' => $this->tone(),
            'trend' => $this->trend($value, $this->previousValue($context)),
        ];
    }
}
