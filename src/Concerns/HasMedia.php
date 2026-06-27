<?php

namespace Ngos\AdminCore\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Ngos\AdminCore\Models\MediaItem;

/**
 * Polymorphic media attachments — any model can own multiple library files per named collection, reusing files
 * from the media library. Pair with the `media`/`gallery` generator field types + the
 * <x-admin-core::media-collection /> form control.
 *
 *   class Product extends Model { use HasMedia; }
 *
 *   $product->media;                          // all attached MediaItems (ordered)
 *   $product->mediaIn('gallery');             // one collection, in order
 *   $product->firstMediaUrl('gallery');       // URL or null
 *   $product->attachMedia($item, 'gallery');  // append one
 *   $product->syncMedia([3, 7, 1], 'gallery');// replace the collection in this order
 */
trait HasMedia
{
    public static function bootHasMedia(): void
    {
        // The pivot can't FK-cascade to a polymorphic owner, so detach a record's media when it's really
        // deleted. A soft delete keeps the attachments (they return if the record is restored).
        static::deleting(function ($model) {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }
            $model->media()->detach();
        });
    }

    public function media(): MorphToMany
    {
        return $this->morphToMany(MediaItem::class, 'mediable')
            ->withPivot(['collection', 'sort'])
            ->withTimestamps()
            ->orderByPivot('sort');
    }

    /** The attached media in a collection, in order. */
    public function mediaIn(string $collection = 'default'): Collection
    {
        return $this->media->where('pivot.collection', $collection)->values();
    }

    public function firstMedia(string $collection = 'default'): ?MediaItem
    {
        return $this->mediaIn($collection)->first();
    }

    public function firstMediaUrl(string $collection = 'default'): ?string
    {
        return $this->firstMedia($collection)?->url;
    }

    /** Append one library item to a collection. */
    public function attachMedia(MediaItem|int $item, string $collection = 'default'): void
    {
        $id = $item instanceof MediaItem ? $item->getKey() : $item;
        $sort = (int) ($this->media()->wherePivot('collection', $collection)->max('sort') ?? -1) + 1;

        $this->media()->attach($id, ['collection' => $collection, 'sort' => $sort]);
        $this->unsetRelation('media');
    }

    /**
     * Replace a collection with exactly $mediaItemIds, in the given order (duplicates + non-integers dropped).
     *
     * @param  array<int, int|string>  $mediaItemIds
     */
    public function syncMedia(array $mediaItemIds, string $collection = 'default'): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $mediaItemIds))));

        $this->media()->wherePivot('collection', $collection)->detach();
        foreach ($ids as $sort => $id) {
            $this->media()->attach($id, ['collection' => $collection, 'sort' => $sort]);
        }
        $this->unsetRelation('media');
    }
}
