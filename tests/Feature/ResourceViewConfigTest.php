<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Support\View\RelationDisplayColumn;
use Ngos\AdminCore\Support\View\SearchColumnSet;
use Ngos\AdminCore\Support\View\SortColumnSet;

/*
 * Backlog #6 (RFC-0009 Rev 2) — Resource View Configuration formalization. Three resource-owned, immutable,
 * stateless value objects: RelationDisplayColumn (the column to display/sort/search ONE relation by its label,
 * or NONE), and the broad multi-column SearchColumnSet / SortColumnSet. This is a FORMALIZATION: no observable
 * behavior changes. These tests pin (a) the descriptor-derived RelationDisplayColumn defaults incl. NONE for a
 * computed/absent label, (b) that labelColumn() is byte-for-byte ac_display_column() (route-key fallback kept),
 * and (c) that the column sets preserve — never narrow — the resource's columns. Each test declares its own
 * anonymous model so the per-class descriptor memo starts empty.
 */

beforeEach(function () {
    Schema::dropIfExists('rvc_nodes');
    Schema::create('rvc_nodes', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->string('title')->nullable();
    });
    Schema::dropIfExists('rvc_bare');
    Schema::create('rvc_bare', fn (Blueprint $t) => tap($t)->id());
});

afterEach(function () {
    Schema::dropIfExists('rvc_nodes');
    Schema::dropIfExists('rvc_bare');
});

/* ───────────────────────────── RelationDisplayColumn ───────────────────────────── */

it('defaults RelationDisplayColumn to the related model canonical label backing column', function () {
    $m = new class extends Model
    {
        protected $table = 'rvc_nodes';
        public $timestamps = false;
    };

    $rdc = RelationDisplayColumn::for($m);

    expect($rdc->column())->toBe('name')
        ->and($rdc->isNone())->toBeFalse()
        ->and($rdc->searchable())->toBeTrue()
        ->and($rdc->sortable())->toBeTrue()
        ->and($rdc->labelColumn())->toBe('name');
});

it('honours a displayColumn() override for RelationDisplayColumn', function () {
    $m = new class extends Model
    {
        protected $table = 'rvc_nodes';
        public $timestamps = false;

        public function displayColumn(): string
        {
            return 'title';
        }
    };

    expect(RelationDisplayColumn::for($m)->column())->toBe('title')
        ->and(RelationDisplayColumn::for($m)->labelColumn())->toBe('title');
});

it('produces NONE for a computed label (a declared accessor that is not a physical column)', function () {
    $m = new class extends Model
    {
        protected $table = 'rvc_bare';
        public $timestamps = false;

        public function displayColumn(): string
        {
            return 'full_name'; // an accessor, not a column on rvc_bare
        }
    };

    $rdc = RelationDisplayColumn::for($m);

    // Formal view: NONE — not sortable/searchable by a label column, and no route-key fallback on the SQL side.
    expect($rdc->column())->toBeNull()
        ->and($rdc->isNone())->toBeTrue()
        ->and($rdc->searchable())->toBeFalse()
        ->and($rdc->sortable())->toBeFalse()
        // Display side is unchanged: labelColumn() is still the accessor name, exactly as ac_display_column().
        ->and($rdc->labelColumn())->toBe('full_name')
        ->and($rdc->labelColumn())->toBe(ac_display_column($m));
});

it('produces NONE for a label-less model while labelColumn keeps the route-key fallback (byte-for-byte)', function () {
    $m = new class extends Model
    {
        protected $table = 'rvc_bare';
        public $timestamps = false;
    };

    $rdc = RelationDisplayColumn::for($m);

    expect($rdc->column())->toBeNull()
        ->and($rdc->isNone())->toBeTrue()
        ->and($rdc->searchable())->toBeFalse()
        // Backward compatibility: the DISPLAY column is still the route key — never removed here (that is gated).
        ->and($rdc->labelColumn())->toBe($m->getRouteKeyName())
        ->and($rdc->labelColumn())->toBe(ac_display_column($m));
});

it('labelColumn() is byte-for-byte ac_display_column() for every kind (no behavioural change)', function () {
    $column = new class extends Model
    {
        protected $table = 'rvc_nodes';
        public $timestamps = false;
    };
    $computed = new class extends Model
    {
        protected $table = 'rvc_bare';
        public $timestamps = false;

        public function displayColumn(): string
        {
            return 'full_name';
        }
    };
    $none = new class extends Model
    {
        protected $table = 'rvc_bare';
        public $timestamps = false;
    };

    foreach ([$column, $computed, $none] as $m) {
        expect(RelationDisplayColumn::for($m)->labelColumn())->toBe(ac_display_column($m));
    }
});

it('resolves RelationDisplayColumn from the class type even when the instance getTable() is aliased (fidelity)', function () {
    $m = new class extends Model
    {
        protected $table = 'rvc_nodes';
        public $timestamps = false;
    };
    $m->setTable('laravel_reserved_0'); // simulate a self-join alias on the instance

    expect(RelationDisplayColumn::for($m)->column())->toBe('name');
});

/* ───────────────────────────── SearchColumnSet / SortColumnSet ───────────────────────────── */

it('SearchColumnSet preserves the resource broad column set (no narrowing) and does not derive from one column', function () {
    $set = SearchColumnSet::of(['name', 'email', 'phone']);

    expect($set->columns())->toBe(['name', 'email', 'phone']) // order preserved, nothing dropped
        ->and($set->contains('email'))->toBeTrue()
        ->and($set->contains('missing'))->toBeFalse()
        ->and($set->isEmpty())->toBeFalse();
});

it('SearchColumnSet de-duplicates and keeps declared strings verbatim, ignoring only non-strings', function () {
    // A redundant duplicate is removed (cannot change which rows an OR-LIKE matches). A declared blank string is
    // KEPT verbatim — dropping it would change set membership vs the strict in_array a whitelist uses; a non-string
    // (never a column name) is ignored so columns() stays list<string>.
    expect(SearchColumnSet::of(['name', 'name', 'email'])->columns())->toBe(['name', 'email'])
        ->and(SearchColumnSet::of(['name', '', 'email'])->columns())->toBe(['name', '', 'email'])
        ->and(SearchColumnSet::of(['name', 0])->columns())->toBe(['name']);
});

it('SearchColumnSet is empty only when the resource declares no columns', function () {
    expect(SearchColumnSet::of([])->isEmpty())->toBeTrue()
        ->and(SearchColumnSet::of(['name'])->isEmpty())->toBeFalse();
});

it('SearchColumnSet merge only widens (immutable union), never narrows', function () {
    $a = SearchColumnSet::of(['name', 'email']);
    $b = SearchColumnSet::of(['email', 'phone']);
    $merged = $a->merge($b);

    expect($merged->columns())->toBe(['name', 'email', 'phone'])
        ->and($a->columns())->toBe(['name', 'email']); // original unchanged (immutable)
});

it('SortColumnSet whitelists exactly the resource sortable columns (broad, distinct type)', function () {
    $set = SortColumnSet::of(['lft', 'name', 'created_at']);

    // A structural column (lft) is kept — sort is NOT derived from a single display column.
    expect($set->contains('lft'))->toBeTrue()
        ->and($set->contains('name'))->toBeTrue()
        ->and($set->contains('not_sortable'))->toBeFalse()
        ->and($set->columns())->toBe(['lft', 'name', 'created_at']);
});

it('SortColumnSet::contains reproduces the strict in_array whitelist byte-for-byte, even for malformed whitelists', function () {
    // For a well-formed whitelist, AND for the malformed edge cases (a declared blank string; a stray value):
    // contains() must return exactly what `in_array($column, $sortable, true)` returned — the applySort() check
    // it replaces has no downstream identifier guard, so any membership drift would be an observable behaviour change.
    $candidates = ['name', 'created_at', 'price', 'lft', '', '0', 'evil'];
    foreach ([['name', 'created_at', 'price'], ['lft'], [''], []] as $sortable) {
        $set = SortColumnSet::of($sortable);
        foreach ($candidates as $candidate) {
            expect($set->contains($candidate))->toBe(in_array($candidate, $sortable, true));
        }
    }

    // A non-string entry is ignored and never matches the requested (string) sort column — strict semantics: the
    // old `in_array('0', [0], true)` was already false, so no `?sort=0` becomes newly accepted.
    $mixed = SortColumnSet::of(['name', 0]);
    expect($mixed->contains('0'))->toBeFalse()
        ->and($mixed->contains('name'))->toBeTrue()
        ->and($mixed->columns())->toBe(['name']);
});
