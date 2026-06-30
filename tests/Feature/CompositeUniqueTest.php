<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/* Composite unique works at runtime: the DB constraint is the hard backstop, and the generated
   Rule::unique(...)->where(...) gives the clean validation message before it hits the database. */

beforeEach(function () {
    Schema::dropIfExists('combos');
    Schema::create('combos', function (Blueprint $t) {
        $t->id();
        $t->string('sku');
        $t->integer('branch_id');
        $t->timestamps();
        $t->unique(['sku', 'branch_id']); // what uniqueConstraints() emits
    });
});

it('the DB constraint rejects a duplicate combination but allows a different one', function () {
    DB::table('combos')->insert(['sku' => 'A1', 'branch_id' => 1]);

    // A different combination is fine (same sku, different branch).
    DB::table('combos')->insert(['sku' => 'A1', 'branch_id' => 2]);
    expect(DB::table('combos')->count())->toBe(2);

    // The exact pair again violates the composite unique.
    expect(fn () => DB::table('combos')->insert(['sku' => 'A1', 'branch_id' => 1]))
        ->toThrow(QueryException::class);
});

it('the generated Rule::unique(...)->where(...) fails a duplicate pair and passes a fresh one', function () {
    DB::table('combos')->insert(['sku' => 'A1', 'branch_id' => 1]);

    // The exact rule the generator emits for the `sku` field of a sku+branch_id composite.
    $rules = fn ($branch) => ['sku' => [Rule::unique('combos', 'sku')->where('branch_id', $branch)]];

    expect(Validator::make(['sku' => 'A1', 'branch_id' => 1], $rules(1))->fails())->toBeTrue()  // taken
        ->and(Validator::make(['sku' => 'A1', 'branch_id' => 2], $rules(2))->fails())->toBeFalse() // free
        ->and(Validator::make(['sku' => 'B9', 'branch_id' => 1], $rules(1))->fails())->toBeFalse();
});

it('the update rule ignores the row being edited', function () {
    $id = DB::table('combos')->insertGetId(['sku' => 'A1', 'branch_id' => 1]);

    // Re-saving the same row (its own pair) must pass thanks to ->ignore.
    $rule = ['sku' => [Rule::unique('combos', 'sku')->ignore($id)->where('branch_id', 1)]];

    expect(Validator::make(['sku' => 'A1', 'branch_id' => 1], $rule)->fails())->toBeFalse();
});
