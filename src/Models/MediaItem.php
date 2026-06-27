<?php

namespace Ngos\AdminCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Ngos\AdminCore\Support\Media;

/**
 * A row in the media library — one uploaded file, reusable across resources. The bytes live on the configured
 * uploads disk (Support\Media); this is the browsable registry on top. Addressed by its public uuid.
 *
 * @property string $uuid
 * @property string $name
 * @property string $path
 * @property string|null $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string $collection
 * @property string|null $url
 * @property bool $is_image
 */
class MediaItem extends Model
{
    protected $fillable = ['name', 'path', 'disk', 'mime', 'size', 'width', 'height', 'collection', 'alt', 'user_id'];

    protected $casts = ['size' => 'integer', 'width' => 'integer', 'height' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (MediaItem $item) => $item->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** The public URL for the stored file (CDN-aware, via Support\Media). */
    public function getUrlAttribute(): ?string
    {
        return Media::url($this->path);
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
