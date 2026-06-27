<?php

namespace Ngos\AdminCore\Dashboard;

use Illuminate\Support\Str;

/**
 * A dashboard widget. Generic + app-agnostic: the package renders it, your app supplies the data. Subclass
 * StatWidget / ChartWidget / ListWidget for the common shapes, or extend this directly for a custom partial.
 * Widgets are declared in config('admin-core.dashboard.widgets') as a class-string or, for simple cases, an
 * inline config array (see {@see ConfigWidget}).
 */
abstract class Widget
{
    /** Stable id, used for the lazy-load endpoint + per-user layout. */
    public function key(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    abstract public function title(): string;

    /**
     * The payload handed to this widget's partial for the active range.
     *
     * @return array<string,mixed>
     */
    abstract public function data(DashboardContext $context): array;

    /** A 'stat'|'chart'|'list' shorthand, or a full custom view name. */
    public function type(): string
    {
        return 'stat';
    }

    /** The Blade view that renders this widget's payload. */
    public function partial(): string
    {
        return match ($this->type()) {
            'stat', 'chart', 'list' => "admin-core::dashboard.{$this->type()}",
            default => $this->type(),
        };
    }

    /** Grid width out of 12 columns (responsive). */
    public function colSpan(): int
    {
        return 3;
    }

    /** Permission required to see this widget (null = always visible). */
    public function permission(): ?string
    {
        return null;
    }

    /** Seconds to cache this widget's data per (key + range + user); 0 = no cache. */
    public function cacheSeconds(): int
    {
        return 0;
    }

    /** Defer the data load to an AJAX call (renders a skeleton first) — for heavy widgets. */
    public function lazy(): bool
    {
        return false;
    }

    /** Auto-refresh interval in seconds (0 = static). */
    public function refreshSeconds(): int
    {
        return 0;
    }

    public function icon(): ?string
    {
        return null;
    }
}
