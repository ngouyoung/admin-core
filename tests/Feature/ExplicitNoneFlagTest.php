<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Support\View\RelationDisplayColumn;

/*
 * Backlog #12 (RFC-0009 Rev 2) — the v3.0.0 MAJOR flip. Explicit NONE is now the DEFAULT: a genuinely label-less
 * related model renders as NONE (an empty cell) instead of leaking its route key. `admin-core.explicit_none`
 * remains only as a DEPRECATED opt-out (set it to false to restore the pre-v3.0.0 route-key fallback during
 * migration). These tests pin the new default, the opt-out, and that the flip touches ONLY the label read path —
 * not a 'column'/'computed' label, and not the descriptor, ac_display_column, RelationDisplayColumn, or search/sort.
 * Each test declares its own anonymous model so the per-class descriptor memo starts empty.
 */

beforeEach(function () {
    foreach (['en_named', 'en_bare', 'en_computed'] as $t) {
        Schema::dropIfExists($t);
    }
    Schema::create('en_named', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
    });
    Schema::create('en_bare', fn (Blueprint $t) => tap($t)->id());               // no name/title/label → kind 'none'
    Schema::create('en_computed', function (Blueprint $t) {
        $t->id();
        $t->string('first_name');
        $t->string('last_name');
    });
});

afterEach(function () {
    config(['admin-core.explicit_none' => true]); // reset to the v3.0.0 default so an opt-out test can't leak
    foreach (['en_named', 'en_bare', 'en_computed'] as $t) {
        Schema::dropIfExists($t);
    }
});

/** A hydrated row of a label-less ('none') related model. */
function enBareRow(): Model
{
    $proto = new class extends Model
    {
        protected $table = 'en_bare';
        public $timestamps = false;
        protected $guarded = [];
    };
    DB::table('en_bare')->insert(['id' => 1]);

    return $proto->newQuery()->firstOrFail();
}

it('defaults the flag to ON — Explicit NONE is the v3.0.0 default', function () {
    expect(config('admin-core.explicit_none'))->toBeTrue();
});

it('default: a label-less related model resolves to Explicit NONE (null), not the route key', function () {
    $bare = enBareRow();

    expect(ac_related_label($bare))->toBeNull();
});

it('deprecated opt-out (explicit_none=false) restores the pre-v3.0.0 route-key fallback', function () {
    $bare = enBareRow();

    config(['admin-core.explicit_none' => false]);

    expect(ac_related_label($bare))->toBe((string) $bare->getKey())
        ->and(ac_related_label($bare))->toBe(ac_localize($bare->{$bare->getRouteKeyName()}));
});

it('a conventional label (name) still renders in both the default and the opt-out', function () {
    $proto = new class extends Model
    {
        protected $table = 'en_named';
        public $timestamps = false;
        protected $guarded = [];
    };
    DB::table('en_named')->insert(['name' => 'Alpha']);
    $row = $proto->newQuery()->firstOrFail();

    expect(ac_related_label($row))->toBe('Alpha'); // default (Explicit NONE) — kind 'column' is unaffected
    config(['admin-core.explicit_none' => false]);
    expect(ac_related_label($row))->toBe('Alpha'); // opt-out — still unaffected
});

it('a computed label still renders its accessor value in both the default and the opt-out', function () {
    $proto = new class extends Model
    {
        protected $table = 'en_computed';
        public $timestamps = false;
        protected $guarded = [];

        public function displayColumn(): string
        {
            return 'full_name'; // an accessor, not a column → descriptor kind 'computed'
        }

        public function getFullNameAttribute(): string
        {
            return $this->first_name . ' ' . $this->last_name;
        }
    };
    DB::table('en_computed')->insert(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $row = $proto->newQuery()->firstOrFail();

    expect(ac_related_label($row))->toBe('Ada Lovelace');   // default — computed is NOT gated
    config(['admin-core.explicit_none' => false]);
    expect(ac_related_label($row))->toBe('Ada Lovelace');   // opt-out — unchanged
});

it('is null-safe for an absent relation in both flag states', function () {
    expect(ac_related_label(null))->toBeNull();
    config(['admin-core.explicit_none' => false]);
    expect(ac_related_label(null))->toBeNull();
});

it('the flip touches neither ac_display_column, RelationDisplayColumn, nor the descriptor', function () {
    $bare = enBareRow();
    $key = $bare->getRouteKeyName();

    // The MAJOR flip lives ONLY in the label read path (ac_related_label). The column facade keeps its route-key
    // fallback so search/sort never target a missing column, and RelationDisplayColumn's semantics are unchanged —
    // regardless of the flag.
    foreach ([true, false] as $flag) {
        config(['admin-core.explicit_none' => $flag]);
        expect(ac_display_column($bare))->toBe($key)
            ->and(RelationDisplayColumn::for($bare)->labelColumn())->toBe($key)
            ->and(RelationDisplayColumn::for($bare)->column())->toBeNull()   // formal NONE, unchanged
            ->and(RelationDisplayColumn::for($bare)->kind)->toBe('none');
    }
});

it('is deterministic: repeated resolutions under a fixed flag are stable', function () {
    $bare = enBareRow();

    // default (Explicit NONE)
    expect(ac_related_label($bare))->toBeNull()
        ->and(ac_related_label($bare))->toBeNull();

    config(['admin-core.explicit_none' => false]);
    expect(ac_related_label($bare))->toBe((string) $bare->getKey())
        ->and(ac_related_label($bare))->toBe((string) $bare->getKey());
});
