<?php

namespace Ngos\AdminCore\Dashboard;

use Closure;
use Illuminate\Support\Str;
use Ngos\AdminCore\Dashboard\Concerns\ComputesTrend;

/**
 * Wraps an inline config-array widget, so a simple dashboard needs no widget class. In
 * config('admin-core.dashboard.widgets') you may pass an array instead of a class-string:
 *
 *   ['type' => 'stat', 'title' => 'Users', 'icon' => 'bi-people',
 *    'value' => fn (DashboardContext $c) => User::count(),
 *    'previous' => fn (DashboardContext $c) => ...,            // optional, drives the trend arrow
 *    'link' => '/admin/users'],                                // optional drill-down
 *
 *   ['type' => 'chart', 'title' => 'Signups', 'col' => 6, 'chart' => fn ($c) => [...]],
 *   ['type' => 'list',  'title' => 'Latest',  'rows'  => fn ($c) => [...]],
 *
 * Recognised keys: type, title, key, icon, col, can (permission), cache, lazy, refresh, plus the
 * type-specific value/previous/link/tone/format (stat), chart (chart), rows/empty (list). Any value may be a
 * closure receiving the DashboardContext.
 */
class ConfigWidget extends Widget
{
    use ComputesTrend;

    /** @param  array<string,mixed>  $config */
    public function __construct(private array $config) {}

    public function key(): string
    {
        return $this->config['key'] ?? Str::slug($this->config['title'] ?? 'widget');
    }

    public function title(): string
    {
        return (string) ($this->config['title'] ?? '');
    }

    public function type(): string
    {
        return $this->config['type'] ?? 'stat';
    }

    public function colSpan(): int
    {
        return (int) ($this->config['col'] ?? ($this->type() === 'stat' ? 3 : 6));
    }

    public function permission(): ?string
    {
        return $this->config['can'] ?? null;
    }

    public function cacheSeconds(): int
    {
        return (int) ($this->config['cache'] ?? 0);
    }

    public function lazy(): bool
    {
        return (bool) ($this->config['lazy'] ?? false);
    }

    public function refreshSeconds(): int
    {
        return (int) ($this->config['refresh'] ?? 0);
    }

    public function icon(): ?string
    {
        return $this->config['icon'] ?? null;
    }

    public function data(DashboardContext $context): array
    {
        return match ($this->type()) {
            'chart' => [
                'title' => $this->title(),
                'key' => $this->key(),
                'chart' => $this->resolve('chart', $context, []),
            ],
            'list' => [
                'title' => $this->title(),
                'rows' => $this->resolve('rows', $context, []),
                'empty' => $this->config['empty'] ?? 'Nothing yet.',
            ],
            default => $this->statData($context),
        };
    }

    private function statData(DashboardContext $context): array
    {
        $value = $this->resolve('value', $context, 0);
        $previous = array_key_exists('previous', $this->config) ? $this->resolve('previous', $context, null) : null;
        $formatter = $this->config['format'] ?? null;

        return [
            'title' => $this->title(),
            'value' => $formatter instanceof Closure
                ? $formatter($value)
                : (is_numeric($value) ? number_format((float) $value) : (string) $value),
            'icon' => $this->icon(),
            'link' => $this->config['link'] ?? null,
            'tone' => (string) ($this->config['tone'] ?? '1'),
            'trend' => $this->trend($value, $previous),
        ];
    }

    private function resolve(string $key, DashboardContext $context, $default)
    {
        $value = $this->config[$key] ?? $default;

        return $value instanceof Closure ? $value($context) : $value;
    }
}
