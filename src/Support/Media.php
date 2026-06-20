<?php

namespace Ngos\AdminCore\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

/**
 * One place for every image/file upload + URL in admin-core, so compression and
 * storage/CDN are configured once (config('admin-core.uploads')) instead of scattered
 * `->store('…','public')` / `asset('storage/…')` calls.
 *
 * - store(): re-encodes images to WebP (downscaled to max_width) at the configured
 *   quality, then writes to the configured disk. Non-images, or any encode failure
 *   (no GD/Imagick WebP support), fall back to storing the original untouched.
 * - url():   builds the public URL — a `cdn_url` prefix if set, else the disk's url()
 *   (so an s3/CloudFront disk serves from the CDN with no code change).
 * - delete(): removes a stored path from the configured disk.
 */
class Media
{
    /** Store an upload (UploadedFile) or raw binary string under $dir, return the stored path. */
    public static function store(UploadedFile|string $file, string $dir): string
    {
        $dir = trim($dir, '/');

        if (config('admin-core.uploads.compress', true) && class_exists(ImageManager::class)) {
            try {
                return self::storeWebp($file, $dir);
            } catch (\Throwable) {
                // Not an image, or no WebP support — fall back to storing the original.
            }
        }

        if ($file instanceof UploadedFile) {
            return $file->store($dir, self::disk());
        }

        $path = $dir . '/' . Str::uuid() . '.jpg';
        Storage::disk(self::disk())->put($path, $file);

        return $path;
    }

    /** Public URL for a stored path (CDN prefix if configured, else the disk's URL). */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            return $path; // already absolute (e.g. a remote avatar)
        }

        if ($cdn = config('admin-core.uploads.cdn_url')) {
            return rtrim($cdn, '/') . '/' . ltrim($path, '/');
        }

        return Storage::disk(self::disk())->url($path);
    }

    /** Delete a stored path from the configured disk. */
    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::disk())->delete($path);
        }
    }

    public static function disk(): string
    {
        return config('admin-core.uploads.disk', 'public');
    }

    /** Re-encode to WebP (downscaled to max_width) and store; returns the .webp path. */
    protected static function storeWebp(UploadedFile|string $file, string $dir): string
    {
        $image = ImageManager::gd()->read($file instanceof UploadedFile ? $file->getRealPath() : $file);

        $maxWidth = (int) config('admin-core.uploads.max_width', 1600);
        if ($maxWidth > 0 && $image->width() > $maxWidth) {
            $image->scaleDown(width: $maxWidth);
        }

        $binary = (string) $image->toWebp(quality: (int) config('admin-core.uploads.quality', 82));
        $path = $dir . '/' . Str::uuid() . '.webp';
        Storage::disk(self::disk())->put($path, $binary);

        return $path;
    }
}
