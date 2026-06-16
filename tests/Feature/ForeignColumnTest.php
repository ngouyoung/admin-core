<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\RelCategory;
use Ngos\AdminCore\Tests\Fixtures\RelGadget;

/*
 * Proves the queries the generator emits for a searchable/sortable belongsTo list column actually
 * run and return the right rows: the filterColumn body (whereHas on the related name) and the
 * orderColumn body (a correlated subquery on the related name).
 */

beforeEach(function () {
    Schema::create('rel_categories', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('rel_gadgets', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('category_id');
    });

    $phones = RelCategory::create(['name' => 'Phones']);
    $audio = RelCategory::create(['name' => 'Audio']);
    RelGadget::create(['name' => 'Pixel', 'category_id' => $phones->id]);
    RelGadget::create(['name' => 'Earbuds', 'category_id' => $audio->id]);
});

afterEach(function () {
    Schema::dropIfExists('rel_gadgets');
    Schema::dropIfExists('rel_categories');
});

it('searches the list by the related name (the generated filterColumn body)', function () {
    $matched = RelGadget::query()
        ->whereHas('category', fn ($rq) => $rq->where('name', 'like', '%Phon%'))
        ->pluck('name');

    expect($matched)->toContain('Pixel')->not->toContain('Earbuds');
});

it('sorts the list by the related name (the generated orderColumn subquery)', function () {
    // Order by the category name via the same correlated subquery the generator emits.
    $sub = RelCategory::select('name')->whereColumn('rel_categories.id', 'rel_gadgets.category_id');

    $asc = RelGadget::query()->orderBy($sub, 'asc')->pluck('name');
    $desc = RelGadget::query()->orderBy(clone $sub, 'desc')->pluck('name');

    // Audio < Phones alphabetically → Earbuds first asc, Pixel first desc.
    expect($asc->first())->toBe('Earbuds')
        ->and($desc->first())->toBe('Pixel');
});
