<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Backlog #4 (RFC-0009 Rev 2) — the additive, READ-SIDE ac_display_descriptor(): the full display-column
 * descriptor {kind: column|computed|none, column, columns}. It resolves from the immutable type (inheriting the
 * Backlog #1 composition-fidelity fix), is deterministic and stateless, and does NOT change ac_display_column().
 * Each test declares its own anonymous model so the per-class memo starts empty.
 */

beforeEach(function () {
    Schema::dropIfExists('desc_nodes');
    Schema::create('desc_nodes', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('parent_id')->nullable();
        $t->string('name')->nullable();
        $t->string('title')->nullable();
        $t->string('label')->nullable();
    });
    Schema::dropIfExists('desc_bare');
    Schema::create('desc_bare', function (Blueprint $t) {
        $t->id();
        $t->string('first_name');
        $t->string('last_name');
    });
});

afterEach(function () {
    Schema::dropIfExists('desc_nodes');
    Schema::dropIfExists('desc_bare');
});

it('describes a conventional label as a column with its declared column dependency', function () {
    $m = new class extends Model
    {
        protected $table = 'desc_nodes';
        public $timestamps = false;
    };

    expect(ac_display_descriptor($m))->toBe(['kind' => 'column', 'column' => 'name', 'columns' => ['name']]);
});

it('describes an explicit displayColumn() override as a column', function () {
    $m = new class extends Model
    {
        protected $table = 'desc_nodes';
        public $timestamps = false;

        public function displayColumn(): string
        {
            return 'title';
        }
    };

    expect(ac_display_descriptor($m))->toBe(['kind' => 'column', 'column' => 'title', 'columns' => ['title']]);
});

it('describes a computed label (a declared name that is not a physical column) as computed with no declared deps', function () {
    $m = new class extends Model
    {
        protected $table = 'desc_bare';
        public $timestamps = false;

        public function displayColumn(): string
        {
            return 'full_name'; // an accessor, not a column on desc_bare
        }
    };

    expect(ac_display_descriptor($m))->toBe(['kind' => 'computed', 'column' => 'full_name', 'columns' => []]);
});

it('describes a label-less model as explicit NONE (while ac_display_column keeps its route-key fallback)', function () {
    Schema::create('desc_labelless', fn (Blueprint $t) => tap($t)->id());
    $m = new class extends Model
    {
        protected $table = 'desc_labelless';
        public $timestamps = false;
    };

    expect(ac_display_descriptor($m))->toBe(['kind' => 'none', 'column' => null, 'columns' => []]);
    // Backward compatibility: the string helper is unchanged — it still returns the route key, not NONE.
    expect(ac_display_column($m))->toBe($m->getRouteKeyName());
    Schema::dropIfExists('desc_labelless');
});

it('resolves the descriptor from the class type even when the instance getTable() is aliased (fidelity)', function () {
    $m = new class extends Model
    {
        protected $table = 'desc_nodes';
        public $timestamps = false;
    };
    $m->setTable('laravel_reserved_0'); // simulate a self-join alias on the instance

    expect(ac_display_descriptor($m))->toBe(['kind' => 'column', 'column' => 'name', 'columns' => ['name']]);
});

it('is deterministic: an aliased resolution never poisons a later one for the same class', function () {
    $proto = new class extends Model
    {
        protected $table = 'desc_nodes';
        public $timestamps = false;
    };

    $first = ac_display_descriptor((clone $proto)->setTable('laravel_reserved_1'));
    $second = ac_display_descriptor($proto);

    expect($first)->toBe(['kind' => 'column', 'column' => 'name', 'columns' => ['name']])
        ->and($second)->toBe($first);
});

it('does not change ac_display_column() for any existing case (backward compatibility)', function () {
    // name-first convention
    $named = new class extends Model
    {
        protected $table = 'desc_nodes';
        public $timestamps = false;
    };
    expect(ac_display_column($named))->toBe('name');

    // override still wins and returns its string verbatim
    $override = new class extends Model
    {
        protected $table = 'desc_nodes';
        public $timestamps = false;

        public function displayColumn(): string
        {
            return 'title';
        }
    };
    expect(ac_display_column($override))->toBe('title');
});
