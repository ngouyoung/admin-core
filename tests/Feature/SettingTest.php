<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// Load the *actual shipped* Setting stub so we test the real published model.
require_once __DIR__ . '/../../stubs/access/Models/Setting.php.stub';

beforeEach(function () {
    Schema::dropIfExists('settings');
    Schema::create('settings', function (Blueprint $table) {
        $table->id();
        $table->string('key')->unique();
        $table->text('value')->nullable();
        $table->string('group')->default('general');
        $table->timestamps();
    });
    Cache::flush();
});

it('stores and reads a setting with a fallback', function () {
    \App\Models\Setting::set('app_name', 'Acme');

    expect(\App\Models\Setting::get('app_name'))->toBe('Acme');
    expect(\App\Models\Setting::get('missing', 'default'))->toBe('default');
});

it('caches settings as a plain array, not a Collection', function () {
    \App\Models\Setting::set('app_name', 'Acme');

    // Regression: caching a Collection caused an "incomplete object" 500 (v1.1.9).
    expect(\App\Models\Setting::cached())->toBeArray();
});

it('invalidates the cache when a setting changes', function () {
    \App\Models\Setting::set('app_name', 'One');
    expect(\App\Models\Setting::get('app_name'))->toBe('One');

    \App\Models\Setting::set('app_name', 'Two');
    expect(\App\Models\Setting::get('app_name'))->toBe('Two');
});

it('exposes the setting() helper with a fallback', function () {
    \App\Models\Setting::set('app_name', 'Acme');

    expect(setting('app_name'))->toBe('Acme');
    expect(setting('nope', 'fallback'))->toBe('fallback');
});
