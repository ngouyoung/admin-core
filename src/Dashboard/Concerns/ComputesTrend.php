<?php

namespace Ngos\AdminCore\Dashboard\Concerns;

/** Turns a current + previous metric into a trend payload (delta, percentage, direction). */
trait ComputesTrend
{
    /**
     * @return array{delta: float, pct: float, dir: string}|null  null when there's nothing to compare
     */
    protected function trend(float|int|string|null $value, float|int|null $previous): ?array
    {
        if ($previous === null || ! is_numeric($value)) {
            return null;
        }
        $value = (float) $value;
        $previous = (float) $previous;
        $delta = $value - $previous;
        $pct = $previous != 0.0 ? ($delta / abs($previous)) * 100 : ($delta != 0.0 ? 100.0 : 0.0);

        return [
            'delta' => $delta,
            'pct' => round($pct, 1),
            'dir' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
        ];
    }
}
