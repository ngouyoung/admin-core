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

it('renders the field HasMedia collection so the shared picker can scope browse + upload to it', function () {
    // Without data-ac-collection the picker sends no collection, so every field's uploads land in the
    // server's 'default' bucket and its browse shows the whole unfiltered library.
    $html = Blade::render('<x-admin-core::media-collection name="certs" collection="certificates" :multiple="true" />');

    expect($html)->toContain('data-ac-collection="certificates"');
});

it('names the remove button for screen readers (aria-label on the icon-only ×)', function () {
    $html = Blade::render(
        '<x-admin-core::media-collection name="photos" :items="$items" :multiple="true" />',
        ['items' => [['id' => 5, 'url' => '/storage/x.png', 'is_image' => true]]],
    );

    // The attached tile's × and the label the JS uses for picked tiles — both announce "Remove".
    expect($html)->toContain('data-ac-media-remove aria-label="Remove"')
        ->toContain('data-ac-remove-label="Remove"');
});

it('renders the upload dropzone keyboard-reachable (role=button + tabindex)', function () {
    // A plain div can't be tabbed to, so a keyboard user could never open the file dialog.
    // role+tabindex make it focusable; media-picker.js activates it on Enter/Space.
    $html = Blade::render('<x-admin-core::media-collection name="photos" :multiple="true" />');

    expect($html)->toContain('data-ac-picker-dropzone role="button" tabindex="0"');
});
