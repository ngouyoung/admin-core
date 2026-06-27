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
