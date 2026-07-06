<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Concerns\HasMedia;
use Ngos\AdminCore\Models\MediaItem;

/* The HasMedia trait: polymorphic, ordered, per-collection media attachments (reusing the library). */

class HasMediaWidget extends Model
{
    use HasMedia;

    protected $guarded = [];

    protected $table = 'has_media_widgets';
}

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
    Schema::create('mediables', function (Blueprint $t) {
        $t->id();
        $t->foreignId('media_item_id')->constrained('media_items')->cascadeOnDelete();
        $t->morphs('mediable');
        $t->string('collection')->default('default');
        $t->unsignedInteger('sort')->default(0);
        $t->timestamps();
    });
    Schema::create('has_media_widgets', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('mediables');
    Schema::dropIfExists('media_items');
    Schema::dropIfExists('has_media_widgets');
});

function makeMedia(string $name = 'x.png'): MediaItem
{
    return MediaItem::create(['name' => $name, 'path' => 'media/' . $name, 'disk' => 'public', 'mime' => 'image/png', 'size' => 1]);
}

it('attaches and reads media in a collection', function () {
    $w = HasMediaWidget::create(['name' => 'W']);
    $a = makeMedia('a.png');
    $w->attachMedia($a, 'gallery');
    $w->attachMedia(makeMedia('b.png'), 'gallery');

    expect($w->mediaIn('gallery'))->toHaveCount(2)
        ->and($w->firstMedia('gallery')->is($a))->toBeTrue()
        ->and($w->firstMediaUrl('gallery'))->not->toBeNull();
});

it('enforces media_scope=own on the ATTACH side: syncMedia/attachMedia drop a foreign upload (IDOR closed)', function () {
    // The read paths (list/owns/scoped) were already (user_id,guard)-scoped under 'own', but a resource form/API
    // could POST a foreign media id: exists:media_items,id validated it, syncMedia attached it, and the URL was
    // re-served — a cross-tenant IDOR. The attach side now drops any id the actor doesn't own.
    config()->set('admin-core.uploads.media_scope', 'own');
    $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 1])); // actor = (id 1, web guard)

    $mine = MediaItem::create(['name' => 'mine.png', 'path' => 'm/mine.png', 'user_id' => 1, 'guard' => 'web']);
    $foreign = MediaItem::create(['name' => 'foreign.png', 'path' => 'm/foreign.png', 'user_id' => 2, 'guard' => 'web']);
    $w = HasMediaWidget::create(['name' => 'W']);

    // syncMedia with BOTH ids → only the owned one attaches; the foreign id is silently dropped.
    $w->syncMedia([$mine->id, $foreign->id], 'gallery');
    expect($w->mediaIn('gallery')->pluck('id')->all())->toBe([$mine->id]);

    // attachMedia with the foreign id → no-op; with the owned id → attaches.
    $w->attachMedia($foreign->id, 'gallery');
    expect($w->mediaIn('gallery')->pluck('id')->all())->toBe([$mine->id]);
    $w->attachMedia($mine->id, 'other');
    expect($w->mediaIn('other')->pluck('id')->all())->toBe([$mine->id]);
});

it('leaves attach unrestricted under the default shared media scope (no behaviour change)', function () {
    $w = HasMediaWidget::create(['name' => 'W']);
    $a = MediaItem::create(['name' => 'a.png', 'path' => 'm/a.png', 'user_id' => 99, 'guard' => 'web']); // not "mine"
    $w->syncMedia([$a->id], 'gallery');   // shared pool → any id attaches
    expect($w->mediaIn('gallery')->pluck('id')->all())->toBe([$a->id]);
});

it('syncs a collection in order, replacing the previous set', function () {
    $w = HasMediaWidget::create(['name' => 'W']);
    $a = makeMedia('a.png');
    $b = makeMedia('b.png');
    $c = makeMedia('c.png');

    $w->syncMedia([$c->id, $a->id], 'gallery');
    expect($w->mediaIn('gallery')->pluck('id')->all())->toBe([$c->id, $a->id]);

    $w->syncMedia([$b->id], 'gallery');
    expect($w->mediaIn('gallery')->pluck('id')->all())->toBe([$b->id]);
});

it('keeps collections separate', function () {
    $w = HasMediaWidget::create(['name' => 'W']);
    $w->attachMedia(makeMedia('a.png'), 'gallery');
    $w->attachMedia(makeMedia('b.png'), 'docs');

    expect($w->mediaIn('gallery'))->toHaveCount(1)
        ->and($w->mediaIn('docs'))->toHaveCount(1)
        ->and($w->media)->toHaveCount(2);
});

it('syncing one collection leaves other collections intact', function () {
    $w = HasMediaWidget::create(['name' => 'W']);
    $w->attachMedia(makeMedia('doc.pdf'), 'docs');
    $w->syncMedia([makeMedia('g.png')->id], 'gallery');

    // The make-or-break invariant: detach(wherePivot collection) must scope to the one collection.
    expect($w->mediaIn('docs'))->toHaveCount(1)
        ->and($w->mediaIn('gallery'))->toHaveCount(1);
});

it('refuses to delete a library item while it is still attached, then allows it once free', function () {
    $w = HasMediaWidget::create(['name' => 'W']);
    $item = makeMedia('a.png');
    $w->attachMedia($item, 'gallery');
    $lib = app(\Ngos\AdminCore\Support\MediaLibrary::class);

    expect($lib->delete($item))->toBeFalse();              // in use → refused
    expect(MediaItem::find($item->id))->not->toBeNull();   // and not removed

    $w->media()->detach();
    expect($lib->delete($item))->toBeTrue();               // now unreferenced → removed
});

it('detaches media when the owner is hard-deleted', function () {
    $w = HasMediaWidget::create(['name' => 'W']);
    $w->attachMedia(makeMedia('a.png'), 'gallery');
    expect(DB::table('mediables')->count())->toBe(1);

    $w->delete();
    expect(DB::table('mediables')->count())->toBe(0);
});
