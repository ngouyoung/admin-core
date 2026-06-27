<?php

namespace Ngos\AdminCore\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
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
        [$width, $height] = $this->dimensions($file);

        return MediaItem::create([
            'name' => mb_substr($file->getClientOriginalName(), 0, 255), // the column is string(255); a longer name would 500

            'path' => Media::store($file, 'media/' . $collection),
            'disk' => Media::disk(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'collection' => $collection,
            'user_id' => auth()->id(),
        ]);
    }

    /** Remove a library item — both the row and the underlying file. */
    public function delete(MediaItem $item): void
    {
        Media::delete($item->path);
        $item->delete();
    }

    /** A query over the library, newest first, optionally narrowed by a name search + a collection. */
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
    private function dimensions(UploadedFile $file): array
    {
        if (str_starts_with((string) $file->getMimeType(), 'image/')) {
            $size = @getimagesize($file->getRealPath());
            if ($size !== false) {
                return [$size[0], $size[1]];
            }
        }

        return [null, null];
    }
}
