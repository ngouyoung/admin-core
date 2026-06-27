<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Ngos\AdminCore\Models\MediaItem;
use Ngos\AdminCore\Support\MediaLibrary;

/* The media library registry + service: store an upload, register it, browse/filter, delete. */

beforeEach(function () {
    Schema::create('media_items', function (Blueprint $t) {
        $t->id();
        $t->uuid('uuid')->unique();
        $t->string('name');
        $t->string('path');
        $t->string('disk')->default('public');
        $t->string('mime')->nullable();
        $t->unsignedBigInteger('size')->default(0);
        $t->unsignedInteger('width')->nullable();
        $t->unsignedInteger('height')->nullable();
        $t->string('collection')->default('default');
        $t->string('alt')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->timestamps();
    });
    Storage::fake('public');
    config(['admin-core.uploads.compress' => false]); // keep the fake bytes intact (no WebP re-encode in tests)
});

afterEach(fn () => Schema::dropIfExists('media_items'));

it('stores an upload into the library and registers it', function () {
    $item = app(MediaLibrary::class)->store(UploadedFile::fake()->image('logo.png', 120, 80), 'brand');

    expect($item)->toBeInstanceOf(MediaItem::class)
        ->and($item->name)->toBe('logo.png')
        ->and($item->collection)->toBe('brand')
        ->and($item->is_image)->toBeTrue()
        ->and($item->width)->toBe(120)
        ->and($item->uuid)->not->toBeEmpty();

    expect(MediaItem::count())->toBe(1);
    Storage::disk('public')->assertExists($item->path);
});

it('deletes a library item and its underlying file', function () {
    $lib = app(MediaLibrary::class);
    $item = $lib->store(UploadedFile::fake()->image('x.png'), 'default');
    $path = $item->path;

    $lib->delete($item);

    expect(MediaItem::count())->toBe(0);
    Storage::disk('public')->assertMissing($path);
});

it('filters the library by name search and by collection', function () {
    $lib = app(MediaLibrary::class);
    $lib->store(UploadedFile::fake()->image('apple.png'), 'fruit');
    $lib->store(UploadedFile::fake()->image('banana.png'), 'fruit');
    $lib->store(UploadedFile::fake()->image('car.png'), 'vehicle');

    expect($lib->query(search: 'app')->count())->toBe(1)
        ->and($lib->query(collection: 'fruit')->count())->toBe(2)
        ->and($lib->collections())->toContain('fruit', 'vehicle');
});
