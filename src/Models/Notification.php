<?php

namespace Ngos\AdminCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Ngos\AdminCore\Concerns\HasPublicUuid;

/**
 * The Notification Platform's owned in-app notification row — the store the InApp channel writes and the (future)
 * Notification Center reads. Hybrid identity via {@see HasPublicUuid} (bigint `id` + public `uuid`). Internal to the
 * platform: products never touch this model; they go through the platform facade and receive DTOs/events.
 *
 * `data` is an immutable presentation payload (written once on delivery, never updated — read-state lives in
 * `read_at`). Queries use the indexed columns (`type`, `read_at`, `guard`, `notifiable_*`), never the json.
 *
 * @property int $id
 * @property string $uuid
 * @property string $notifiable_type
 * @property int|string $notifiable_id
 * @property ?string $guard
 * @property string $type
 * @property array<string, mixed> $data
 * @property ?\Illuminate\Support\Carbon $read_at
 *
 * @internal
 */
class Notification extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function booted(): void
    {
        // Per-(recipient, guard) unread-count cache invalidation on every write — the MenuItem booted() precedent.
        static::saved(fn (self $n) => Cache::forget(self::unreadCacheKey($n->notifiable_type, $n->notifiable_id, $n->guard)));
        static::deleted(fn (self $n) => Cache::forget(self::unreadCacheKey($n->notifiable_type, $n->notifiable_id, $n->guard)));
    }

    /** The cached unread count for a recipient on a guard (the Center's hot path). */
    public static function unreadCountFor(string $notifiableType, int|string $notifiableId, ?string $guard): int
    {
        return (int) Cache::remember(
            self::unreadCacheKey($notifiableType, $notifiableId, $guard),
            now()->addDay(),
            fn () => self::query()
                ->where('notifiable_type', $notifiableType)
                ->where('notifiable_id', $notifiableId)
                ->where('guard', $guard)
                ->whereNull('read_at')
                ->count(),
        );
    }

    private static function unreadCacheKey(string $notifiableType, int|string $notifiableId, ?string $guard): string
    {
        // Guard-aware key (portal isolation) — mirrors the dashboard cache's {guard}#{id} shape.
        return 'admin-core:notifications:unread:' . ($guard ?? 'null') . '#' . $notifiableType . '#' . $notifiableId;
    }
}
