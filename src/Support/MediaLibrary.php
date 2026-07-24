<?php

namespace Ngos\AdminCore\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\MediaItem;

/**
 * The media library: stores uploads through Support\Media (WebP compression / disk / CDN) AND registers each in
 * media_items so it can be browsed and reused across resources. A thin layer over Media + the MediaItem model.
 */
class MediaLibrary
{
    /** Store an upload into the library (compress + persist + register) and return the MediaItem. */
    public function store(UploadedFile $file, string $collection = 'default'): MediaItem
    {
        $collection = trim($collection) ?: 'default';

        // Store first, then measure the STORED asset: Media::store() re-encodes images to WebP downscaled to
        // max_width, so the original UploadedFile's dimensions don't match the file that's actually served.
        $path = Media::store($file, 'media/' . $collection);
        [$width, $height] = $this->dimensions($path, (string) $file->getMimeType());

        [$userId, $guard] = $this->actor();

        return MediaItem::create([
            // Strip HTML-dangerous chars from the user-supplied filename (defense-in-depth vs an XSS payload in the
            // name) and cap at the column length (255) so a long name can't 500.
            'name' => mb_substr(str_replace(['<', '>', '"', "'"], '', $file->getClientOriginalName()), 0, 255),

            'path' => $path,
            'disk' => Media::disk(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'collection' => $collection,
            'user_id' => $userId,
            'guard' => $guard,
        ]);
    }

    /**
     * The uploading/acting user's [id, guard], resolved guard-aware — the portal guard for a portal user, else
     * the default guard. auth()->id() reads ONLY the default guard, so on a non-default-guard portal it is null,
     * which would land every portal user's upload in a shared user_id=NULL bucket and defeat the 'own' scope.
     * Iterate the portal guards (the same pattern as Dashboard::currentUser / ApprovalController).
     *
     * @return array{0: int|string|null, 1: string|null}
     */
    protected function actor(): array
    {
        // Delegated to the single canonical resolver (AR-1) — this was a copy of the guard walk in the INVERSE
        // (default-first) order; it now resolves portal-first, identical to every other locus.
        return \Ngos\AdminCore\Support\ActorResolver::actor();
    }

    /**
     * Remove a library item — the row and the underlying file. Refuses (returns false) while it's still
     * attached to any record via HasMedia, so deleting from the library can't silently strip a file out of
     * galleries that still reference it.
     */
    public function delete(MediaItem $item): bool
    {
        if ($this->inUse($item)) {
            return false;
        }

        Media::delete($item->path);
        $item->delete();

        return true;
    }

    /** Is this library item attached to any model (a HasMedia collection)? */
    public function inUse(MediaItem $item): bool
    {
        return Schema::hasTable('mediables')
            && DB::table('mediables')->where('media_item_id', $item->getKey())->exists();
    }

    /**
     * May the current user act on (delete) this item under the configured library scope? Always true for the
     * default 'shared' pool; for 'own' the item must belong to the current user — so a crafted uuid can't reach
     * (delete) another user's / another portal's upload. The controller 404s an out-of-scope item.
     */
    public function owns(MediaItem $item): bool
    {
        if (config('admin-core.uploads.media_scope', 'shared') !== 'own') {
            return true;
        }
        [$userId, $guard] = $this->actor();

        // Match on (user_id, guard) — user_id alone would let id 5 on 'merchant' delete id 5's 'web' upload.
        return $userId !== null
            && (string) $item->user_id === (string) $userId
            && (string) $item->guard === (string) $guard;
    }

    /**
     * A query over the library, newest first, optionally narrowed by a name search + a collection, and scoped to
     * the current user when `uploads.media_scope` is 'own' (so browse/list can't enumerate other users' uploads).
     *
     * @return Builder<MediaItem>
     */
    public function query(?string $search = null, ?string $collection = null): Builder
    {
        return $this->scoped(MediaItem::query())
            // Escaped LIKE (shared helper) so a `_`/`%` in the filename search matches literally, not as a wildcard.
            ->when($search, fn (Builder $q) => \Ngos\AdminCore\Support\Search::whereLike($q, 'name', (string) $search))
            ->when($collection, fn (Builder $q) => $q->where('collection', $collection))
            ->latest();
    }

    /** The distinct collections (folders) present in the library — scoped to the current user under 'own'. */
    public function collections(): array
    {
        return $this->scoped(MediaItem::query())->distinct()->orderBy('collection')->pluck('collection')->all();
    }

    /**
     * Apply the configured library scope. 'own' limits to the current user's uploads by (user_id, guard) —
     * matching how store() records them, and guard-aware so a portal user can't enumerate the default guard's
     * (or another portal's) library. 'shared' (the default) leaves the query untouched — one global pool.
     *
     * @param  Builder<MediaItem>  $query
     * @return Builder<MediaItem>
     */
    protected function scoped(Builder $query): Builder
    {
        if (config('admin-core.uploads.media_scope', 'shared') === 'own') {
            [$userId, $guard] = $this->actor();
            // Fail closed: an unresolved user (null id) scopes to nothing rather than the whole pool.
            $query->where('user_id', $userId)->where('guard', $guard);
        }

        return $query;
    }

    /**
     * Filter a set of media-item ids to those the current actor may ATTACH to a record — the WRITE-side of the
     * same boundary query()/owns()/scoped() enforce on READ. Under uploads.media_scope='own', only the actor's
     * own (user_id, guard) uploads pass, so a resource form / API / import can't attach (and thus re-serve)
     * another tenant's file by a guessable sequential id. Under the default 'shared' scope every id passes (one
     * global pool). The input is int-cast + de-duplicated; the caller's order is preserved for the survivors.
     * (Trusted programmatic code that must bypass the scope can attach via the `media()` relation directly.)
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    public function ownedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || config('admin-core.uploads.media_scope', 'shared') !== 'own') {
            return $ids;
        }
        $allowed = array_map('intval', $this->scoped(MediaItem::whereIn('id', $ids))->pluck('id')->all());

        return array_values(array_filter($ids, fn ($id) => in_array($id, $allowed, true)));
    }

    /**
     * @return array{0: int|null, 1: int|null} [width, height] for an image upload, else [null, null]
     */
    /**
     * Dimensions of the STORED file (read back from its disk), so width/height reflect the served asset —
     * WebP, downscaled to max_width — not the pre-compression upload. Non-images / unreadable → [null, null].
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function dimensions(string $path, string $originalMime): array
    {
        if (! str_starts_with($originalMime, 'image/')) {
            return [null, null];
        }

        try {
            $binary = \Illuminate\Support\Facades\Storage::disk(Media::disk())->get($path);
            $size = is_string($binary) ? @getimagesizefromstring($binary) : false;
            if ($size !== false) {
                return [$size[0], $size[1]];
            }
        } catch (\Throwable) {
            // remote/CDN disk we can't read back, or a decode failure — leave dimensions unknown
        }

        return [null, null];
    }
}
