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

it('the settings request validates uploads by type, blocking executable/markup files', function () {
    Schema::table('settings', fn ($t) => $t->string('type')->default('text'));
    require_once __DIR__ . '/../../stubs/access/Http/Requests/Setting/UpdateSettingRequest.php.stub';

    \Illuminate\Support\Facades\DB::table('settings')->insert([
        ['key' => 'logo', 'value' => null, 'group' => 'general', 'type' => 'image'],
        ['key' => 'manual', 'value' => null, 'group' => 'general', 'type' => 'file'],
        ['key' => 'app_name', 'value' => 'x', 'group' => 'general', 'type' => 'text'],
    ]);

    $rules = (new \App\Http\Requests\Setting\UpdateSettingRequest)->rules();

    // image/file settings get a type-specific upload rule with an explicit mimes allowlist…
    expect($rules['settings.logo'])->toContain('image')->toContain('mimes:jpg,jpeg,png,webp,gif');
    expect($rules['settings.manual'])->toContain('file')->toContain('mimes:pdf,doc,docx,xls,xlsx,csv,txt,zip');
    // …a plain text setting is not an upload, so it gets no file rule.
    expect($rules)->not->toHaveKey('settings.app_name');
});
