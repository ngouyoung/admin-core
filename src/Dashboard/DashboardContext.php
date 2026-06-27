<?php

namespace Ngos\AdminCore\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The active dashboard view-state every widget shares: the selected date range and the matching
 * previous period (used for trend deltas). Built from the request — a preset `?range=today|7d|30d|month|all`
 * or an explicit `?from=&to=`. Widgets scope their queries to {@see $from}/{@see $to} and compare against
 * {@see $previousFrom}/{@see $previousTo}. A null from/to means "all time" (no date scoping).
 *
 * App-agnostic: it carries only dates — nothing about any particular domain or model.
 */
class DashboardContext
{
    /** @param  array<string,mixed>  $params  extra per-user/per-request params (e.g. a saved filter) */
    public function __construct(
        public readonly string $range,
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
        public readonly ?CarbonImmutable $previousFrom,
        public readonly ?CarbonImmutable $previousTo,
        public readonly array $params = [],
    ) {}

    /** The presets offered in the date-range toolbar (value => label). */
    public static function presets(): array
    {
        return [
            'today' => 'Today',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            'month' => 'This month',
            'all' => 'All time',
        ];
    }

    public static function fromRequest(Request $request): self
    {
        $now = CarbonImmutable::now();

        // Explicit custom window wins; its previous period is the same-length window immediately before it
        // (disjoint — ends one second before the current window starts). A reversed from/to is normalised.
        $rawFrom = $request->date('from');
        $rawTo = $request->date('to');
        if ($rawFrom && $rawTo) {
            $from = CarbonImmutable::parse($rawFrom);
            $to = CarbonImmutable::parse($rawTo);
            if ($from->greaterThan($to)) {
                [$from, $to] = [$to, $from];
            }
            $from = $from->startOfDay();
            $to = $to->endOfDay();
            $duration = $from->diffInSeconds($to);

            return new self('custom', $from, $to, $from->subSeconds($duration + 1), $from->subSecond(), $request->all());
        }

        $range = (string) $request->query('range', config('admin-core.dashboard.default_range', '30d'));

        return self::fromPreset($range, $now, $request->all());
    }

    public static function fromPreset(string $range, ?CarbonImmutable $now = null, array $params = []): self
    {
        $now ??= CarbonImmutable::now();

        [$from, $to, $prevFrom, $prevTo] = match ($range) {
            // The previous window ends one second before the current one begins (where they'd otherwise share
            // a boundary), so an inclusive whereBetween on each can't double-count a row on that instant.
            'today' => [$now->startOfDay(), $now, $now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            '7d' => [$now->subDays(7), $now, $now->subDays(14), $now->subDays(7)->subSecond()],
            'month' => [$now->startOfMonth(), $now, $now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()],
            'all' => [null, null, null, null],
            default => [$now->subDays(30), $now, $now->subDays(60), $now->subDays(30)->subSecond()], // '30d'
        };

        $range = array_key_exists($range, self::presets()) ? $range : '30d';

        return new self($range, $from, $to, $prevFrom, $prevTo, $params);
    }

    /** Scope an Eloquent/query builder to the active range on $column (no-op for "all time"). */
    public function scope($query, string $column = 'created_at')
    {
        if ($this->from && $this->to) {
            $query->whereBetween($column, [$this->from, $this->to]);
        }

        return $query;
    }

    /** Scope to the PREVIOUS comparison window on $column (no-op for "all time"). */
    public function scopePrevious($query, string $column = 'created_at')
    {
        if ($this->previousFrom && $this->previousTo) {
            $query->whereBetween($column, [$this->previousFrom, $this->previousTo]);
        }

        return $query;
    }

    public function hasRange(): bool
    {
        return $this->from !== null && $this->to !== null;
    }

    /**
     * A stable key segment uniquely identifying this window for caching: the preset name for presets, but the
     * actual from/to timestamps for a custom window — so two different custom ranges never share a cache entry.
     */
    public function cacheSignature(): string
    {
        if ($this->range === 'custom' && $this->from && $this->to) {
            return 'custom:' . $this->from->getTimestamp() . '-' . $this->to->getTimestamp();
        }

        return $this->range;
    }
}
