<?php

namespace Ngos\AdminCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * A database-managed sidebar menu item — the source for `menu_source = 'database'`.
 *
 * The Menu manager screen (shipped by --access) lets admins add / edit / delete /
 * drag-reorder / nest items at runtime; {@see tree()} turns the rows into the exact
 * nested array shape the `admin-core::sidebar-menu` component + {@see \Ngos\AdminCore\Support\Sidebar}
 * already render (so permission/route filtering is shared with the config menu).
 *
 * A row with no `route`, no `url` and no children renders as a section header
 * (`['header' => label]`) — the same convention as the config menu.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $label
 * @property string|null $icon
 * @property string|null $route
 * @property string|null $url
 * @property string|null $match
 * @property string|null $permission
 * @property string|null $target
 * @property int $sort
 * @property bool $is_active
 */
class MenuItem extends Model
{
    /** Cache key for the built tree (forgotten on every write — see {@see booted()} + the reorder endpoint). */
    public const CACHE_KEY = 'admin-core.menu.tree';

    protected $fillable = [
        'parent_id', 'label', 'icon', 'route', 'url', 'match', 'permission', 'target', 'sort', 'is_active',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    protected static function booted(): void
    {
        // CRUD goes through model events; the drag-reorder endpoint uses a bulk query
        // update (no events), so it calls forgetCache() itself.
        static::saved(fn () => self::forgetCache());
        static::deleted(fn () => self::forgetCache());
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The nested menu array (cached). Shape matches config('admin-core.menu') so it can be
     * passed straight to {@see \Ngos\AdminCore\Support\Sidebar::items()}.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tree(): array
    {
        if (! Schema::hasTable((new self)->getTable())) {
            return []; // table not installed (no --access) — sidebar falls back gracefully
        }

        return Cache::rememberForever(self::CACHE_KEY, function () {
            // Group by parent, keying roots as 0 (ids start at 1) — groupBy() would
            // otherwise bucket null parents under '' and ->get(null) would miss them.
            $byParent = self::query()->where('is_active', true)
                ->orderBy('sort')->orderBy('id')
                ->get()
                ->groupBy(fn (self $item) => $item->parent_id ?? 0);

            return self::build($byParent, 0);
        });
    }

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Collection<int, self>>  $byParent
     * @return array<int, array<string, mixed>>
     */
    protected static function build(Collection $byParent, int $parentId): array
    {
        $out = [];

        foreach ($byParent->get($parentId, collect()) as $item) {
            $children = self::build($byParent, $item->id);

            // No link and no children → a section header (matches the config menu convention).
            if ($children === [] && ! $item->route && ! $item->url) {
                $out[] = ['header' => $item->label];

                continue;
            }

            $node = array_filter([
                'label' => $item->label,
                'icon' => $item->icon,
                'route' => $item->route,
                'url' => $item->url,
                'match' => $item->match,
                'can' => $item->permission,
                'target' => $item->target && $item->target !== '_self' ? $item->target : null,
            ], fn ($v) => $v !== null && $v !== '');

            if ($children !== []) {
                $node['children'] = $children;
            }

            $out[] = $node;
        }

        return $out;
    }
}
