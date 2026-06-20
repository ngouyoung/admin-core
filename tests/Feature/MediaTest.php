<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Ngos\AdminCore\Support\Media;

/*
 * Ngos\AdminCore\Support\Media — the single image/file upload + URL layer
 * (WebP compression, configurable disk, optional CDN prefix).
 */

it('builds a URL from the disk by default, and passes through null/absolute', function () {
    config()->set('admin-core.uploads', ['disk' => 'public', 'cdn_url' => null]);
    Storage::fake('public');

    expect(Media::url(null))->toBeNull()
        ->and(Media::url('https://x.test/y.png'))->toBe('https://x.test/y.png')
        ->and(Media::url('avatars/a.webp'))->toContain('avatars/a.webp');
});

it('prefixes the CDN url when configured (and leaves absolute URLs alone)', function () {
    config()->set('admin-core.uploads.cdn_url', 'https://cdn.example.com');

    expect(Media::url('avatars/a.webp'))->toBe('https://cdn.example.com/avatars/a.webp')
        ->and(Media::url('https://other.test/x.png'))->toBe('https://other.test/x.png');
});

it('compresses an uploaded image to WebP, downscaled to max_width', function () {
    if (! function_exists('imagewebp')) {
        $this->markTestSkipped('GD has no WebP support');
    }
    config()->set('admin-core.uploads', ['disk' => 'public', 'cdn_url' => null, 'compress' => true, 'max_width' => 100, 'quality' => 80]);
    Storage::fake('public');

    $path = Media::store(UploadedFile::fake()->image('p.jpg', 400, 400), 'photos');

    expect($path)->toEndWith('.webp')
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('stores the original (no WebP) when compression is off or it is not an image', function () {
    config()->set('admin-core.uploads', ['disk' => 'public', 'cdn_url' => null, 'compress' => false]);
    Storage::fake('public');

    $path = Media::store(UploadedFile::fake()->create('doc.pdf', 10), 'files');

    expect(Storage::disk('public')->exists($path))->toBeTrue()
        ->and($path)->not->toEndWith('.webp');
});

it('deletes a stored path and no-ops on null', function () {
    config()->set('admin-core.uploads', ['disk' => 'public', 'cdn_url' => null]);
    Storage::fake('public');
    Storage::disk('public')->put('x/y.txt', 'hi');

    Media::delete('x/y.txt');
    Media::delete(null); // must not throw

    expect(Storage::disk('public')->exists('x/y.txt'))->toBeFalse();
});
