<?php

namespace Ngos\AdminCore\Dashboard;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\DashboardLayout;

/**
 * Resolves the configured widgets (class-strings or inline config arrays) into Widget instances, filters
 * them by the viewer's permission, and is the single entry point the dashboard component + lazy/data
 * endpoints share. Bound as a singleton so the resolved set is computed once per request.
 */
class Dashboard
{
    /**
     * The container key under which runtime-registered widgets are stored. Bound to the CONTAINER (not a
     * static property) so the registry is scoped to the current app instance — a static array would persist
     * across app reboots in one PHP process (a long-running worker, or a consumer's test suite that boots the
     * app per test), accumulating duplicate registrations from every prior boot.
     */
    private const REGISTERED_KEY = 'admin-core.dashboard.registered';

    /**
     * Register dashboard widget(s) at runtime (class-string, Widget instance, or inline config array). Call
     * from a service provider's boot() so closure-based widgets don't have to live in the (cacheable) config.
     * This is the cache-safe home for a widget that needs a CLOSURE (an inline `'value' => fn ($c) => …`): a
     * closure in config/admin-core.php makes `php artisan config:cache` fatal (var_export can't serialise a
     * Closure), whereas boot() runs on every request even when the config is cached.
     */
    public static function register(mixed ...$widgets): void
    {
        $existing = app()->bound(self::REGISTERED_KEY) ? (array) app(self::REGISTERED_KEY) : [];
        app()->instance(self::REGISTERED_KEY, array_merge($existing, $widgets));
    }

    /** Forget all runtime-registered widgets (test helper / re-registration). */
    public static function flushRegistered(): void
    {
        app()->forgetInstance(self::REGISTERED_KEY);
    }

    /** @return Collection<int,Widget> the widgets the current user may see, in declared order */
    public function widgets(): Collection
    {
        $registered = app()->bound(self::REGISTERED_KEY) ? (array) app(self::REGISTERED_KEY) : [];

        return collect(config('admin-core.dashboard.widgets', []))
            ->merge($registered)
            ->map(fn ($widget) => $this->make($widget))
            ->filter()
            ->filter(fn (Widget $widget) => $this->visible($widget))
            ->unique(fn (Widget $widget) => $widget->key()) // a duplicate key would silently drop a widget in arranged() (keyBy); keep the first
            ->values();
    }

    /** Resolve a single visible widget by key (for the lazy-load endpoint), or null. */
    public function find(string $key): ?Widget
    {
        return $this->widgets()->first(fn (Widget $widget) => $widget->key() === $key);
    }

    /**
     * A widget's data for the active range — cached per (key + range + user) when the widget sets
     * cacheSeconds() > 0, so an expensive query runs at most once per TTL. The component and the lazy
     * endpoint both go through this so caching is applied uniformly.
     *
     * @return array<string,mixed>
     */
    public function payload(Widget $widget, DashboardContext $context): array
    {
        $ttl = $widget->cacheSeconds();
        if ($ttl <= 0) {
            return $widget->data($context);
        }

        $key = 'admin-core:dashboard:' . $widget->key() . ':' . $context->cacheSignature() . ':' . (auth()->id() ?? 'guest');

        return cache()->remember($key, $ttl, fn () => $widget->data($context));
    }

    /**
     * The current user's arranged widget set: their saved order first (widgets added since are appended),
     * with the ones they've hidden removed. Falls back to the declared order when there's no saved layout.
     * The component renders this; the lazy endpoint still resolves any permitted widget via find().
     *
     * @return Collection<int,Widget>
     */
    public function arranged(): Collection
    {
        $all = $this->widgets();
        $layout = $this->layout();
        $order = $layout['order'] ?? [];
        $hidden = $layout['hidden'] ?? [];
        if (! $order && ! $hidden) {
            return $all;
        }

        $byKey = $all->keyBy(fn (Widget $w) => $w->key());

        return collect($order)
            ->map(fn ($key) => $byKey->get($key))
            ->filter()
            ->merge($all->reject(fn (Widget $w) => in_array($w->key(), $order, true)))
            ->reject(fn (Widget $w) => in_array($w->key(), $hidden, true))
            ->values();
    }

    /** The current user's saved layout (['order' => [...], 'hidden' => [...]]), or [] when none. */
    public function layout(): array
    {
        [$userId, $guard] = $this->currentUser();
        if ($userId === null || ! Schema::hasTable('dashboard_layouts')) {
            return [];
        }

        $row = DashboardLayout::query()
            ->where('user_id', $userId)
            ->where('guard', $guard)
            ->first();
        if (! $row) {
            return [];
        }

        return is_array($row->layout) ? $row->layout : [];
    }

    /** Persist the current user's arrangement (the customize-mode save). No-op when unauthenticated. */
    public function saveLayout(array $order, array $hidden): void
    {
        [$userId, $guard] = $this->currentUser();
        if ($userId === null) {
            return;
        }

        // Only persist keys that map to a real, permission-visible widget — never store arbitrary client input.
        $valid = $this->widgets()->map(fn (Widget $w) => $w->key())->all();

        DashboardLayout::query()->updateOrCreate(
            ['user_id' => $userId, 'guard' => $guard],
            ['layout' => [
                'order' => array_values(array_intersect($order, $valid)),
                'hidden' => array_values(array_intersect($hidden, $valid)),
            ]],
        );
    }

    /**
     * The signed-in user's [id, guard] across the default guard AND any configured portal guard — so a portal
     * dashboard (whose user is authenticated on a NON-default guard) resolves, instead of auth()->id() reading
     * only the default guard and returning null (a silent no-op save). The guard is stored alongside so user
     * id 5 on the merchant guard never collides with id 5 on the web guard.
     *
     * @return array{0: int|string|null, 1: ?string}
     */
    protected function currentUser(): array
    {
        $guards = array_unique(array_merge(
            [config('auth.defaults.guard', 'web')],
            array_keys((array) config('admin-core.permission.guards', [])),
        ));

        foreach ($guards as $guard) {
            try {
                if (auth()->guard($guard)->check()) {
                    return [auth()->guard($guard)->id(), (string) $guard];
                }
            } catch (\Throwable) {
                continue; // a guard named in admin-core config but not defined in auth.php — skip
            }
        }

        return [null, null];
    }

    private function make($widget): ?Widget
    {
        if ($widget instanceof Widget) {
            return $widget;
        }
        if (is_array($widget)) {
            return new ConfigWidget($widget);
        }
        if (is_string($widget) && is_subclass_of($widget, Widget::class)) {
            return app($widget);
        }

        return null;
    }

    private function visible(Widget $widget): bool
    {
        $permission = $widget->permission();
        if (! $permission) {
            return true;
        }
        $user = auth()->user();

        return $user !== null && $user->can($permission);
    }
}
