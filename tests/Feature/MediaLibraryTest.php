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
        $t->string('guard')->nullable();
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

it('records the STORED (downscaled) dimensions, not the pre-compression original', function () {
    config()->set('admin-core.uploads.compress', true);
    config()->set('admin-core.uploads.max_width', 60);

    // A 120×80 upload is re-encoded to WebP and downscaled to 60 wide — width/height must reflect the STORED
    // file (60×40), not the original 120×80 that's never served.
    $item = app(MediaLibrary::class)->store(UploadedFile::fake()->image('wide.png', 120, 80), 'brand');

    expect($item->width)->toBe(60)       // downscaled to max_width, not 120
        ->and($item->height)->toBe(40);  // aspect ratio preserved (80 × 60/120)
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

it('scopes the library to the current user when uploads.media_scope=own (browse, collections, delete-guard)', function () {
    // The audit-10 media IDOR: with no scoping, any manage-media holder browses + deletes every user's / every
    // portal's uploads. Opt-in 'own' scope walls each user off to their own items.
    config()->set('admin-core.uploads.media_scope', 'own');
    $lib = app(MediaLibrary::class);

    MediaItem::create(['name' => 'mine.png', 'path' => 'm/mine.png', 'collection' => 'a', 'user_id' => 1, 'guard' => 'web']);
    MediaItem::create(['name' => 'theirs.png', 'path' => 'm/theirs.png', 'collection' => 'b', 'user_id' => 2, 'guard' => 'web']);

    $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 1]));

    expect($lib->query()->pluck('name')->all())->toBe(['mine.png'])                 // only my upload is listed
        ->and($lib->collections())->toBe(['a'])                                      // only my collection
        ->and($lib->owns(MediaItem::where('user_id', 1)->first()))->toBeTrue()       // can delete my own
        ->and($lib->owns(MediaItem::where('user_id', 2)->first()))->toBeFalse();     // NOT another user's (404)
});

it('walls off a portal user from the default guard user of the SAME id under uploads.media_scope=own (audit-11)', function () {
    // The audit-11 regression: store/owns/scoped read auth()->id() (default guard only), so a portal user's
    // uploads landed in a shared user_id=NULL bucket AND id 5 on 'web' collided with id 5 on 'merchant'. The
    // guard column + guard-aware resolution walls them apart.
    config()->set('admin-core.uploads.media_scope', 'own');
    config()->set('admin-core.permission.guards', ['merchant' => []]);
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);
    $lib = app(MediaLibrary::class);

    // Two DIFFERENT people who happen to share numeric id 5 on different guards.
    $web = MediaItem::create(['name' => 'web.png', 'path' => 'm/web.png', 'user_id' => 5, 'guard' => 'web']);
    $merchant = MediaItem::create(['name' => 'merchant.png', 'path' => 'm/merchant.png', 'user_id' => 5, 'guard' => 'merchant']);

    // A merchant-guard user id 5 (default 'web' guard has NO user — the real portal shape).
    auth()->guard('merchant')->setUser(new \Illuminate\Auth\GenericUser(['id' => 5]));

    expect($lib->query()->pluck('name')->all())->toBe(['merchant.png'])   // only the merchant-guard upload…
        ->and($lib->owns($merchant))->toBeTrue()                          // …which they own…
        ->and($lib->owns($web))->toBeFalse();                            // …NOT the web user's of the same id
});

it('skips a portal guard declared in config but missing from auth.php instead of 500ing every media op (audit-15)', function () {
    // actor() iterates the configured portal guards. A guard named in admin-core.permission.guards but NOT
    // defined in auth.php (a typo / renamed guard — the exact misconfig the package tolerates elsewhere) makes
    // auth()->guard($undefined) throw. Every sibling guard-walker (Dashboard/SetLocale/AutoTranslate/LogsActivity/
    // SavedView) catches this and skips; actor() was the lone one that didn't → a single config typo 500'd every
    // upload/list/delete for a portal user whose real guard is ordered AFTER the undefined one.
    config()->set('admin-core.uploads.media_scope', 'own');
    config()->set('admin-core.permission.guards', ['ghost' => [], 'merchant' => []]); // 'ghost' has NO auth.php guard
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);
    $lib = app(MediaLibrary::class);

    $merchant = MediaItem::create(['name' => 'm.png', 'path' => 'm/m.png', 'user_id' => 7, 'guard' => 'merchant']);
    auth()->guard('merchant')->setUser(new \Illuminate\Auth\GenericUser(['id' => 7]));

    // The undefined 'ghost' guard (ordered before 'merchant' in the walk) is skipped, not thrown…
    expect(fn () => $lib->owns($merchant))->not->toThrow(\Throwable::class);
    // …and the real merchant guard still resolves, so ownership + scoping are correct.
    expect($lib->owns($merchant))->toBeTrue()
        ->and($lib->query()->pluck('name')->all())->toBe(['m.png']);
});

it('keeps one shared pool by default (uploads.media_scope=shared) — no behaviour change', function () {
    $lib = app(MediaLibrary::class);
    MediaItem::create(['name' => 'a.png', 'path' => 'm/a.png', 'user_id' => 1]);
    MediaItem::create(['name' => 'b.png', 'path' => 'm/b.png', 'user_id' => 2]);

    $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 1]));

    expect($lib->query()->count())->toBe(2)                                          // everyone sees the whole pool
        ->and($lib->owns(MediaItem::where('user_id', 2)->first()))->toBeTrue();      // and may manage any of it
});
