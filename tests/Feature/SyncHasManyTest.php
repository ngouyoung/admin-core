<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Services\BaseService;
use Ngos\AdminCore\Tests\Fixtures\RelCategory;

/*
 * BaseService::syncHasMany — the master-detail reconcile used by repeater-backed forms (a parent + its
 * line items). Update rows with an id, create new ones, delete the rest; null = leave untouched.
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
});

afterEach(function () {
    Schema::dropIfExists('rel_gadgets');
    Schema::dropIfExists('rel_categories');
});

/** A throwaway service exposing the protected helper. */
function syncService(): BaseService
{
    return new class extends BaseService
    {
        public function run($parent, string $rel, ?array $rows, ?callable $attrs = null): array
        {
            return $this->syncHasMany($parent, $rel, $rows, $attrs);
        }
    };
}

it('creates new rows, updates by id, and deletes the rest', function () {
    $cat = RelCategory::create(['name' => 'Phones']);
    $svc = syncService();

    // First save: two new rows (no id) → both created, category_id auto-set by the relation.
    $svc->run($cat, 'gadgets', [['name' => 'A'], ['name' => 'B']]);
    expect($cat->gadgets()->count())->toBe(2);

    // Reconcile: keep+rename A by id, drop B, add C.
    $a = $cat->gadgets()->where('name', 'A')->first();
    $svc->run($cat, 'gadgets', [['id' => $a->id, 'name' => 'A2'], ['name' => 'C']]);

    expect($cat->gadgets()->pluck('name')->sort()->values()->all())->toBe(['A2', 'C'])
        ->and($cat->gadgets()->whereKey($a->id)->value('name'))->toBe('A2'); // same row, updated
});

it('leaves children untouched when rows is null (the block was not submitted)', function () {
    $cat = RelCategory::create(['name' => 'Phones']);
    $svc = syncService();
    $svc->run($cat, 'gadgets', [['name' => 'A'], ['name' => 'B']]);

    $svc->run($cat, 'gadgets', null);

    expect($cat->gadgets()->count())->toBe(2);
});

it('clears all children on an empty array', function () {
    $cat = RelCategory::create(['name' => 'Phones']);
    $svc = syncService();
    $svc->run($cat, 'gadgets', [['name' => 'A']]);

    $svc->run($cat, 'gadgets', []);

    expect($cat->gadgets()->count())->toBe(0);
});

it('uses the attributes transform to whitelist columns and skip rows (null = skip)', function () {
    $cat = RelCategory::create(['name' => 'Phones']);
    $svc = syncService();

    // A blank row is skipped; only D is kept.
    $svc->run($cat, 'gadgets', [['name' => ''], ['name' => 'D', 'ignored' => 'x']], function ($r) {
        return $r['name'] === '' ? null : ['name' => $r['name']]; // 'ignored' never reaches the model
    });

    expect($cat->gadgets()->pluck('name')->all())->toBe(['D']);
});
