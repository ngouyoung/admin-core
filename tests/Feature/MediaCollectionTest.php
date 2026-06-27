<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/* The <x-admin-core::media-collection> field control + its shared picker modal. */

beforeEach(function () {
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Route::adminCoreMedia());
    Route::getRoutes()->refreshNameLookups();
    view()->share('errors', new \Illuminate\Support\ViewErrorBag);
});

it('renders the attached media + the shared picker modal', function () {
    $html = Blade::render(
        '<x-admin-core::media-collection name="photos" :items="$items" :multiple="true" />',
        ['items' => [['id' => 5, 'url' => '/storage/x.png', 'is_image' => true]]],
    );

    expect($html)
        ->toContain('data-ac-media-collection')
        ->toContain('data-ac-name="photos"')
        ->toContain('name="photos[]"')      // the hidden id input the service reads
        ->toContain('value="5"')            // the pre-attached item
        ->toContain('data-bs-target="#acMediaPicker"')
        ->toContain('id="acMediaPicker"')   // the picker modal (emitted @once)
        ->toContain('data-ac-list-url');
});

it('marks a single (non-multiple) control so the JS replaces instead of appends', function () {
    $html = Blade::render('<x-admin-core::media-collection name="hero" :multiple="false" />');

    expect($html)->toContain('data-ac-multiple="0"')->toContain('Choose media');
});
