<?php

namespace Ngos\AdminCore\Support;

use Illuminate\Support\Facades\Route;

/**
 * Builds the admin sidebar from a declarative menu array (default: config('admin-core.menu')),
 * keeping only the items the current user may see. The rendering lives in the
 * `admin-core::sidebar-menu` component — this class is the (testable) filtering brain.
 *
 * Item shapes:
 *   ['label' => 'Products', 'route' => 'admin.products.index', 'icon' => 'bi bi-box',
 *    'can' => 'list-product', 'match' => 'admin/products*']
 *   ['header' => 'Access']                                  // a section label
 *   ['label' => 'Access', 'icon' => '…', 'match' => 'admin/assessments/*',
 *    'children' => [ …items… ]]                             // a collapsible treeview
 *
 * Visibility rules (per item):
 *   - a `route` that doesn't exist  → hidden (so menu entries for un-installed
 *     features disappear on their own);
 *   - a `can` permission the user lacks → hidden (only when permissions are enabled);
 *   - a treeview parent with no visible children → hidden;
 *   - a `header` with no visible item before the next header → hidden.
 */
class Sidebar
{
    /**
     * The visible, filtered menu tree.
     *
     * @param  array<int, array<string, mixed>>|null  $items  Defaults to config('admin-core.menu').
     * @param  string|null  $guard  Auth guard whose user the `can` checks run against (multi-portal:
     *                              e.g. 'merchant'). Null = the application's default guard.
     */
    public static function items(?array $items = null, ?string $guard = null): array
    {
        return self::prune(self::filter($items ?? config('admin-core.menu', []), $guard));
    }

    /**
     * The visible menu built from the database (the Menu manager / `menu_source = 'database'`),
     * run through the same route + permission filtering as the config menu.
     *
     * @param  string|null  $guard  Auth guard for the `can` checks (multi-portal).
     */
    public static function database(?string $guard = null): array
    {
        $tree = \Ngos\AdminCore\Models\MenuItem::tree();

        // Empty table (e.g. right after a migrate:fresh, before admin-core:menu:import) — fall back to the
        // config menu so the sidebar is never blank. As soon as menu_items has rows, the database menu wins.
        if (blank($tree)) {
            return self::items(config('admin-core.menu', []), $guard);
        }

        return self::items($tree, $guard);
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function filter(array $items, ?string $guard): array
    {
        $out = [];

        foreach ($items as $item) {
            if (isset($item['header'])) {
                $out[] = $item; // kept for now; pruned later if nothing follows it
                continue;
            }

            if (isset($item['children'])) {
                $children = self::filter($item['children'], $guard);
                if ($children !== []) {
                    $item['children'] = $children;
                    $out[] = $item;
                }
                continue;
            }

            if (self::visible($item, $guard)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** A single (leaf) item is visible when its route exists and its permission passes (for the given guard). */
    private static function visible(array $item, ?string $guard): bool
    {
        if (isset($item['route']) && ! Route::has($item['route'])) {
            return false;
        }

        if (! empty($item['can']) && config('admin-core.permission.enabled')) {
            return (bool) auth()->guard($guard)->user()?->can($item['can']);
        }

        return true;
    }

    /** Drop section headers that aren't followed by at least one real item. */
    private static function prune(array $items): array
    {
        $out = [];
        $count = count($items);

        for ($i = 0; $i < $count; $i++) {
            if (isset($items[$i]['header'])) {
                // Look ahead: keep the header only if a non-header item follows before the next header.
                $hasItem = false;
                for ($j = $i + 1; $j < $count && ! isset($items[$j]['header']); $j++) {
                    $hasItem = true;
                    break;
                }
                if (! $hasItem) {
                    continue;
                }
            }
            $out[] = $items[$i];
        }

        return $out;
    }
}
