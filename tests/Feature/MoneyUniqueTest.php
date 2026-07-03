<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Ngos\AdminCore\Console\FieldSet;
use Ngos\AdminCore\Rules\UniqueMoney;

/*
 * A money column stores MINOR units but the form posts the MAJOR amount (validation runs before the cast), so a
 * plain unique rule compares the wrong scale. A single-column money unique now emits UniqueMoney (converts
 * first); a composite / per-record money unique is rejected at generation.
 */

function moneyFs(string $dsl): FieldSet
{
    return (new FieldSet($dsl))->setTable('products');
}

it('emits UniqueMoney for a single-column money unique (config-default currency)', function () {
    expect(moneyFs('price:money^')->storeRules())
        ->toContain("new \\Ngos\\AdminCore\\Rules\\UniqueMoney('products', 'price', null, null, 'id', false)");
});

it('pins the currency in UniqueMoney when the money column is pinned', function () {
    expect(moneyFs('price:money:KHR^')->storeRules())
        ->toContain("new \\Ngos\\AdminCore\\Rules\\UniqueMoney('products', 'price', 'KHR', null, 'id', false)");
});

it('ignores self by route key on update', function () {
    expect(moneyFs('price:money^')->updateRules())
        ->toContain("new \\Ngos\\AdminCore\\Rules\\UniqueMoney('products', 'price', null, \$this->route('id'), 'id', false)");
});

it('rejects a per-record (@currency) money column being unique', function () {
    expect(fn () => moneyFs('cur:string, price:money:@cur^')->storeRules())
        ->toThrow(InvalidArgumentException::class, "per-record (@currency) money column ('price') can't be unique");
});

it('rejects a money column inside a composite unique', function () {
    expect(fn () => moneyFs('sku:string, price:money')->setUniqueGroups([['sku', 'price']]))
        ->toThrow(InvalidArgumentException::class, "can't include the money column 'price'");
});

// -- The rule itself: compares in MINOR units, not the posted major amount ----------------------------

it('UniqueMoney detects a duplicate by MINOR units (not the posted major amount)', function () {
    config()->set('admin-core.money.currency', 'USD'); // 2 decimals → 15.00 == 1500 minor
    Schema::dropIfExists('prices');
    Schema::create('prices', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('price'); // stored minor units
    });
    \Illuminate\Support\Facades\DB::table('prices')->insert(['price' => 1500]); // $15.00 already exists

    // Posting "15.00" (major) must be caught as a duplicate — the whole point (a plain unique:products,price
    // would compare "15.00" against 1500 and MISS it).
    $dup = Validator::make(['price' => '15.00'], ['price' => [new UniqueMoney('prices', 'price', 'USD')]]);
    expect($dup->fails())->toBeTrue();

    // A genuinely different amount passes.
    $ok = Validator::make(['price' => '20.00'], ['price' => [new UniqueMoney('prices', 'price', 'USD')]]);
    expect($ok->fails())->toBeFalse();

    // And $0.15 (stored 15) must NOT collide with $15.00 (stored 1500) — no false positive.
    $noFalsePositive = Validator::make(['price' => '0.15'], ['price' => [new UniqueMoney('prices', 'price', 'USD')]]);
    expect($noFalsePositive->fails())->toBeFalse();
});

it('UniqueMoney ignores the record being updated', function () {
    config()->set('admin-core.money.currency', 'USD');
    Schema::dropIfExists('prices');
    Schema::create('prices', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('price');
    });
    $id = \Illuminate\Support\Facades\DB::table('prices')->insertGetId(['price' => 1500]);

    // Re-saving the SAME row with its own value is fine (self-ignored).
    $self = Validator::make(['price' => '15.00'], ['price' => [new UniqueMoney('prices', 'price', 'USD', $id, 'id')]]);
    expect($self->fails())->toBeFalse();
});


it('UniqueMoney fails validation (not a 500) on an out-of-range amount', function () {
    $failed = false;
    (new \Ngos\AdminCore\Rules\UniqueMoney('mny_products', 'price'))
        ->validate('price', '1e400', function () use (&$failed) { $failed = true; });

    expect($failed)->toBeTrue();
});


it('bounds generated money rules so an INF-range amount fails validation up front', function () {
    expect((new \Ngos\AdminCore\Console\FieldSet('price:money'))->storeRules())
        ->toContain("'between:-99999999999999,99999999999999'");
});
