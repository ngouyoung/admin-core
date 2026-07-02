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
            'user_id' => auth()->id(),
        ]);
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
     * A query over the library, newest first, optionally narrowed by a name search + a collection.
     *
     * @return Builder<MediaItem>
     */
    public function query(?string $search = null, ?string $collection = null): Builder
    {
        return MediaItem::query()
            ->when($search, fn (Builder $q) => $q->where('name', 'like', '%' . $search . '%'))
            ->when($collection, fn (Builder $q) => $q->where('collection', $collection))
            ->latest();
    }

    /** The distinct collections (folders) present in the library. */
    public function collections(): array
    {
        return MediaItem::query()->distinct()->orderBy('collection')->pluck('collection')->all();
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
