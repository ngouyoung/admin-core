<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Backlog #5 (RFC-0009 Rev 2) — Consumer wiring. The descriptor (Backlog #4) is now the SINGLE internal read
 * source: ac_display_column() derives from it, and the read-side label consumers (ac_related_label, and the
 * WebController select/export surfaces that resolve labels through ac_display_column) obtain naming information
 * through the descriptor. This is a pure re-plumbing: observable behavior — the resolved column AND every
 * rendered label — is byte-for-byte identical to before. These tests lock that equivalence.
 *
 * Each test declares its own anonymous model so the per-class memo (in ac_display_descriptor) starts empty.
 */

beforeEach(function () {
    Schema::dropIfExists('wire_named');
    Schema::create('wire_named', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->string('title')->nullable();
    });
    Schema::dropIfExists('wire_titled');
    Schema::create('wire_titled', function (Blueprint $t) {
        $t->id();
        $t->string('title')->nullable();
    });
    Schema::dropIfExists('wire_bare');
    Schema::create('wire_bare', fn (Blueprint $t) => tap($t)->id());
});

afterEach(function () {
    Schema::dropIfExists('wire_named');
    Schema::dropIfExists('wire_titled');
    Schema::dropIfExists('wire_bare');
});

it('makes the descriptor the single source: ac_display_column == descriptor column ?? route key, for every kind', function () {
    // conventional name-first
    $named = new class extends Model
    {
        protected $table = 'wire_named';
        public $timestamps = false;
    };
    // displayColumn() override to a real column
    $override = new class extends Model
    {
        protected $table = 'wire_named';
        public $timestamps = false;

        public function displayColumn(): string
        {
            return 'title';
        }
    };
    // computed label: a declared name that is not a physical column
    $computed = new class extends Model
    {
        protected $table = 'wire_bare';
        public $timestamps = false;

        public function displayColumn(): string
        {
            return 'full_name';
        }
    };
    // label-less: no conventional column, no override -> route-key fallback
    $bare = new class extends Model
    {
        protected $table = 'wire_bare';
        public $timestamps = false;
    };

    foreach ([$named, $override, $computed, $bare] as $m) {
        $d = ac_display_descriptor($m);
        expect(ac_display_column($m))->toBe($d['column'] ?? $m->getRouteKeyName());
    }

    // and the concrete byte-for-byte values are unchanged from the pre-wiring behavior
    expect(ac_display_column($named))->toBe('name')
        ->and(ac_display_column($override))->toBe('title')
        ->and(ac_display_column($computed))->toBe('full_name')
        ->and(ac_display_column($bare))->toBe($bare->getRouteKeyName());
});

it('ac_related_label resolves the label through the descriptor-backed column (name-first), unchanged', function () {
    $proto = new class extends Model
    {
        protected $table = 'wire_named';
        public $timestamps = false;
        protected $guarded = [];
    };
    DB::table('wire_named')->insert(['name' => 'Alpha', 'title' => 'Alpha Title']);
    $row = $proto->newQuery()->first();

    // name wins over title (convention order preserved through the descriptor)
    expect(ac_related_label($row))->toBe('Alpha')
        // exactly what the consumer computes: localize($related->{resolved column})
        ->and(ac_related_label($row))->toBe(ac_localize($row->{ac_display_column($row)}));
});

it('ac_related_label yields Explicit NONE for a label-less related model (v3.0.0 default); the column resolution is unchanged', function () {
    $proto = new class extends Model
    {
        protected $table = 'wire_bare';
        public $timestamps = false;
        protected $guarded = [];
    };
    DB::table('wire_bare')->insertGetId([]);
    $row = $proto->newQuery()->first();

    // v3.0.0: a label-less related model (descriptor kind `none`) renders as NONE, not a leaked route key.
    expect(ac_related_label($row))->toBeNull();
    // The flip is display-only — the underlying descriptor/column resolution still falls back to the route key (so
    // the SQL side never targets a missing column). The wiring through the descriptor is intact.
    expect(ac_display_column($row))->toBe($row->getRouteKeyName())
        ->and(\Ngos\AdminCore\Support\View\RelationDisplayColumn::for($row)->labelColumn())->toBe($row->getRouteKeyName());
});

it('ac_related_label picks up a title-based model through the descriptor (no hardcoded name)', function () {
    $proto = new class extends Model
    {
        protected $table = 'wire_titled';
        public $timestamps = false;
        protected $guarded = [];
    };
    DB::table('wire_titled')->insert(['title' => 'Only A Title']);
    $row = $proto->newQuery()->first();

    expect(ac_related_label($row))->toBe('Only A Title');
});

it('ac_related_label stays null-safe for an absent relation', function () {
    expect(ac_related_label(null))->toBeNull();
});
