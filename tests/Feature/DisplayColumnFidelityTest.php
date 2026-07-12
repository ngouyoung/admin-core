<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Backlog #1 (RFC-0009 Rev 2 — Composition Fidelity). ac_display_column() resolves the display column from the
 * immutable model TYPE (the class's canonical table), NEVER from a mutable runtime instance's getTable(). A
 * relation-existence query (a self-referential whereHas) calls setTable('laravel_reserved_N') on the related
 * instance; probing that alias silently fell back to the route key and poisoned the class-keyed memo. These
 * tests pin the fix. They are driver-agnostic and run on SQLite/PostgreSQL/MySQL via the search-portability CI
 * matrix (the env-aware TestCase). Each test declares its OWN anonymous model so the static per-class memo
 * starts empty and cannot be contaminated by another test.
 */

beforeEach(function () {
    Schema::dropIfExists('fidelity_nodes');
    Schema::create('fidelity_nodes', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('parent_id')->nullable();
        $t->string('name');
    });
});

afterEach(fn () => Schema::dropIfExists('fidelity_nodes'));

it('resolves the display column from the class canonical table, not a mutated instance getTable()', function () {
    $m = new class extends Model
    {
        protected $table = 'fidelity_nodes';
        public $timestamps = false;
    };
    $m->setTable('laravel_reserved_0'); // simulate the self-join alias on THIS instance

    // Resolved from the TYPE's canonical table ('fidelity_nodes'), not the (non-existent) alias — which would
    // otherwise fail the column probe and fall back to the route key ('id').
    expect(ac_display_column($m))->toBe('name');
});

it('resolves the related name inside a self-referential whereHas (FI-6/FI-7 regression)', function () {
    $model = new class extends Model
    {
        protected $table = 'fidelity_nodes';
        public $timestamps = false;

        public function parent(): BelongsTo
        {
            return $this->belongsTo(static::class, 'parent_id');
        }
    };

    $col = null;
    // whereHas aliases the related table to laravel_reserved_N, so $rq->getModel()->getTable() is the alias.
    $model::query()->whereHas('parent', function ($rq) use (&$col) {
        $col = ac_display_column($rq->getModel());
    })->toSql();

    expect($col)->toBe('name'); // was the route key ('id') before the fix
});

it('an aliased resolution never poisons a later resolution for the same class (determinism)', function () {
    $proto = new class extends Model
    {
        protected $table = 'fidelity_nodes';
        public $timestamps = false;
    };

    $aliased = (clone $proto)->setTable('laravel_reserved_1'); // same class, aliased instance
    $first = ac_display_column($aliased);  // the FIRST resolution — must memoise the correct column
    $second = ac_display_column($proto);   // fresh instance of the same class — must not read a poisoned memo

    expect($first)->toBe('name')
        ->and($second)->toBe('name');
});

it('is Octane-safe: repeated resolutions across simulated persistent-worker calls stay correct', function () {
    $proto = new class extends Model
    {
        protected $table = 'fidelity_nodes';
        public $timestamps = false;
    };

    // The static memo persists across calls exactly as it would across requests in a resident Octane worker.
    $results = [];
    foreach (['laravel_reserved_0', null, 'laravel_reserved_2', null] as $table) {
        $inst = clone $proto;
        if ($table !== null) {
            $inst->setTable($table);
        }
        $results[] = ac_display_column($inst);
    }

    expect($results)->toBe(['name', 'name', 'name', 'name']); // never a poisoned route key, whatever the order
});
