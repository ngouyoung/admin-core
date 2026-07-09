<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Ngos\AdminCore\Http\Controllers\WebController;
use Ngos\AdminCore\Services\BaseService;

/*
 * Parent-scoped ordering (admin-core:make --sortable=<column>). BaseService::reorder($ids, $scopeId)
 * PARTITIONS `sort` by the resource's trusted $sortScope column (never a value from HTTP input) and
 * REJECTS the whole operation if any submitted id falls outside the scope. See ADR-0007.
 *
 * The fixture uses a business-agnostic `group_id` parent column so the test asserts the MECHANISM,
 * not any domain (Zero Business Logic in the kernel).
 */

beforeEach(function () {
    Schema::dropIfExists('scoped_widgets');
    Schema::create('scoped_widgets', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('group_id');
        $t->string('name')->nullable();
        $t->integer('sort')->default(0);
        $t->index(['group_id', 'sort']); // the composite index the generator emits
        $t->softDeletes();
    });
});

function scopedWidget(): Model
{
    return new class extends Model
    {
        use SoftDeletes;

        protected $table = 'scoped_widgets';

        protected $guarded = [];

        public $timestamps = false;
    };
}

function makeScopedWidget(int $group, ?string $name = null): Model
{
    $m = scopedWidget();
    $m->group_id = $group;
    $m->name = $name;
    $m->save();

    return $m;
}

/** Service whose ordering is partitioned by the trusted `group_id` column (as the generator would emit). */
function scopedReorderService(): BaseService
{
    return new class(scopedWidget()) extends BaseService
    {
        protected ?string $sortScope = 'group_id';

        public function __construct(Model $model)
        {
            $this->model = $model;
        }
    };
}

/** A plain GLOBAL service (no $sortScope) — for the backward-compatible, lenient reorder($ids) path. */
function globalReorderService(): BaseService
{
    return new class(scopedWidget()) extends BaseService
    {
        public function __construct(Model $model)
        {
            $this->model = $model;
        }
    };
}

it('confines a scoped reorder to the given parent, renumbering 1-based', function () {
    $a = makeScopedWidget(1);
    $b = makeScopedWidget(1);
    $c = makeScopedWidget(1);
    $x = makeScopedWidget(2);
    $y = makeScopedWidget(2);

    scopedReorderService()->reorder([$c->id, $a->id, $b->id], 1);

    expect($c->fresh()->sort)->toBe(1)
        ->and($a->fresh()->sort)->toBe(2)
        ->and($b->fresh()->sort)->toBe(3)
        // The other parent's rows are never touched.
        ->and($x->fresh()->sort)->toBe(0)
        ->and($y->fresh()->sort)->toBe(0);
});

it('REJECTS the whole reorder if any id belongs to another parent (nothing written)', function () {
    $a = makeScopedWidget(1);
    $b = makeScopedWidget(1);
    $x = makeScopedWidget(2); // foreign parent

    expect(fn () => scopedReorderService()->reorder([$a->id, $x->id], 1))
        ->toThrow(ValidationException::class);

    // Atomic: the in-scope rows are NOT half-renumbered, and the foreign row is untouched.
    expect($a->fresh()->sort)->toBe(0)
        ->and($b->fresh()->sort)->toBe(0)
        ->and($x->fresh()->sort)->toBe(0);
});

it('preserves the global contract: a GLOBAL service reorder($ids) renumbers across parents', function () {
    // Backward compatibility: a resource with NO scope column keeps the exact pre-scope global behaviour.
    $a = makeScopedWidget(1);
    $x = makeScopedWidget(2);

    globalReorderService()->reorder([$x->id, $a->id]); // no scope column, no scopeId

    expect($x->fresh()->sort)->toBe(1)
        ->and($a->fresh()->sort)->toBe(2);
});

it('REJECTS a scoped service reorder($ids) with an OMITTED scope id (declared-scope invariant, no writes)', function () {
    // A service that declares $sortScope must never silently run global when the scope value is forgotten.
    // It fails fast with a programming-error exception BEFORE any write — not a global cross-scope renumber.
    $a = makeScopedWidget(1);
    $x = makeScopedWidget(2);

    expect(fn () => scopedReorderService()->reorder([$x->id, $a->id])) // scope id omitted
        ->toThrow(InvalidArgumentException::class);

    expect($a->fresh()->sort)->toBe(0)->and($x->fresh()->sort)->toBe(0); // nothing modified
});

it('REJECTS a scoped service reorder($ids, null) with an EXPLICIT null scope id', function () {
    $a = makeScopedWidget(1);

    expect(fn () => scopedReorderService()->reorder([$a->id], null))
        ->toThrow(InvalidArgumentException::class);

    expect($a->fresh()->sort)->toBe(0);
});

it('still succeeds for a scoped service given a valid scope id', function () {
    // The hardening must not block the legitimate path.
    $a = makeScopedWidget(1);
    $b = makeScopedWidget(1);

    scopedReorderService()->reorder([$b->id, $a->id], 1);

    expect($b->fresh()->sort)->toBe(1)->and($a->fresh()->sort)->toBe(2);
});

it('excludes soft-deleted rows from a scoped reorder (a trashed id is out of scope → rejected)', function () {
    $a = makeScopedWidget(1);
    $b = makeScopedWidget(1);
    scopedReorderService()->delete($a->id); // soft-delete a

    // a is no longer visible through query() → treated as out of scope → whole op rejected.
    expect(fn () => scopedReorderService()->reorder([$a->id, $b->id], 1))
        ->toThrow(ValidationException::class);

    // A reorder over only the live row succeeds.
    scopedReorderService()->reorder([$b->id], 1);
    expect($b->fresh()->sort)->toBe(1);
});

it('honours a query() override on a scoped reorder (tenant scope AND parent scope)', function () {
    $mine = makeScopedWidget(1, 'Mine');
    $theirs = makeScopedWidget(1, 'Theirs');

    // Both share the parent, but only 'Mine' passes the tenant-style query() scope, so a payload that
    // includes 'Theirs' is out of the (query() ∩ parent) scope → rejected.
    $service = new class(scopedWidget()) extends BaseService
    {
        protected ?string $sortScope = 'group_id';

        public function __construct(Model $model)
        {
            $this->model = $model;
        }

        public function query(array|string|null $relation = null): Builder
        {
            return parent::query($relation)->where('name', 'Mine'); // pretend tenant scope
        }
    };

    expect(fn () => $service->reorder([$theirs->id, $mine->id], 1))
        ->toThrow(ValidationException::class);

    $service->reorder([$mine->id], 1);
    expect($mine->fresh()->sort)->toBe(1)
        ->and($theirs->fresh()->sort)->toBe(0); // out of the query() scope → never written
});

it('REJECTS a scoped reorder with a duplicated id (no surprising final position)', function () {
    $a = makeScopedWidget(1);
    $b = makeScopedWidget(1);

    // [a, a, b] would otherwise write a=1 then a=2 (last wins) — a surprising, gapped order. Reject instead.
    expect(fn () => scopedReorderService()->reorder([$a->id, $a->id, $b->id], 1))
        ->toThrow(ValidationException::class);

    expect($a->fresh()->sort)->toBe(0)->and($b->fresh()->sort)->toBe(0); // nothing written
});

it('REJECTS a scoped reorder containing a nonexistent id', function () {
    $a = makeScopedWidget(1);

    expect(fn () => scopedReorderService()->reorder([$a->id, 999999], 1))
        ->toThrow(ValidationException::class);

    expect($a->fresh()->sort)->toBe(0);
});

it('REJECTS a scoped reorder whose ids are entirely nonexistent', function () {
    makeScopedWidget(1); // a real row exists in the scope, but is not in the payload
    expect(fn () => scopedReorderService()->reorder([888, 999], 1))
        ->toThrow(ValidationException::class);
});

it('treats an empty scoped reorder as a harmless no-op (not a rejection)', function () {
    $a = makeScopedWidget(1);
    scopedReorderService()->reorder([], 1); // no ids → nothing to renumber
    expect($a->fresh()->sort)->toBe(0);
});

it('treats an empty GLOBAL reorder as a harmless no-op', function () {
    $a = makeScopedWidget(1);
    globalReorderService()->reorder([]); // global path, empty → nothing written
    expect($a->fresh()->sort)->toBe(0);
});

it('ignores a scope value passed to a GLOBAL (unscoped) service — stays global, no strict validation', function () {
    // A service with no $sortScope treats reorder($ids, $anything) as global: the second arg is inert.
    $a = makeScopedWidget(1);
    $x = makeScopedWidget(2);

    globalReorderService()->reorder([$x->id, $a->id], 999); // 999 is not a real scope — ignored (global path)

    expect($x->fresh()->sort)->toBe(1)->and($a->fresh()->sort)->toBe(2);
});

it('preserves lenient GLOBAL behaviour: a duplicate id is NOT rejected (no accidental breaking change)', function () {
    // The strict duplicate-free contract applies ONLY to the scoped path. Global reorder keeps its
    // pre-existing lenient behaviour so no caller of reorder($ids) breaks.
    $a = makeScopedWidget(1);
    $b = makeScopedWidget(1);

    globalReorderService()->reorder([$a->id, $a->id, $b->id]); // global, tolerant

    // Last write wins for the duplicate (existing behaviour), b takes position 3.
    expect($a->fresh()->sort)->toBe(2)->and($b->fresh()->sort)->toBe(3);
});

it('never derives the sort scope column from HTTP input (the column is a trusted service property)', function () {
    $reorder = new ReflectionMethod(BaseService::class, 'reorder');
    $params = $reorder->getParameters();

    // reorder takes only ($ids, $scopeId) — $scopeId is a VALUE (resolved from a trusted route binding),
    // never the column NAME. The column is the protected $sortScope property, writable only by the
    // generated subclass, so no request can redirect ordering onto an arbitrary column.
    expect($params[0]->getName())->toBe('ids')
        ->and($params[1]->getName())->toBe('scopeId')
        ->and((new ReflectionProperty(BaseService::class, 'sortScope'))->isProtected())->toBeTrue();

    // The framework's GLOBAL reorder endpoint passes only the id list — no scope column/id from the request.
    $src = file_get_contents((new ReflectionClass(WebController::class))->getFileName());
    expect($src)->toContain('$this->service->reorder($this->bulkIds($request))');
});
