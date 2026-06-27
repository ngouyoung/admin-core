<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Ngos\AdminCore\Models\MediaItem;
use Ngos\AdminCore\Support\MediaLibrary;

/* The media library endpoints (Route::adminCoreMedia): upload + delete. */

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
    config(['admin-core.uploads.compress' => false, 'admin-core.permission.enabled' => false]);
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Route::adminCoreMedia());
    Route::getRoutes()->refreshNameLookups();
});

afterEach(fn () => Schema::dropIfExists('media_items'));

it('uploads files into the library via the endpoint', function () {
    $this->post('/admin/media/upload', [
        'files' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png')],
    ])->assertOk()->assertJsonCount(2, 'data');

    expect(MediaItem::count())->toBe(2);
});

it('rejects an upload that carries no files', function () {
    $this->postJson('/admin/media/upload', [])->assertStatus(422);

    expect(MediaItem::count())->toBe(0);
});

it('rejects a dangerous upload (svg / executable) via the allowlist', function () {
    $this->post(
        '/admin/media/upload',
        ['files' => [UploadedFile::fake()->create('x.svg', 4, 'image/svg+xml')]],
        ['Accept' => 'application/json'],
    )->assertStatus(422);

    expect(MediaItem::count())->toBe(0); // never reaches the disk
});

it('deletes a media item (and its file) via the endpoint', function () {
    $item = app(MediaLibrary::class)->store(UploadedFile::fake()->image('x.png'));
    $path = $item->path;

    $this->delete('/admin/media/' . $item->uuid)->assertOk()->assertJson(['ok' => true]);

    expect(MediaItem::count())->toBe(0);
    Storage::disk('public')->assertMissing($path);
});
