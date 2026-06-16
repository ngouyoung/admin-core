<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Http\Controllers\WebController;
use Ngos\AdminCore\Services\BaseService;
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

it('global search matches the related name end-to-end via yajra (OR, not AND, with other columns)', function () {
    // DataTables global-search request for "Phon" — matches the CATEGORY "Phones", not the gadget name.
    request()->merge([
        'draw' => 1, 'start' => 0, 'length' => 10,
        'search' => ['value' => 'Phon', 'regex' => 'false'],
        'columns' => [
            ['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'category', 'name' => 'category', 'searchable' => 'true', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
        ],
    ]);

    $json = \Yajra\DataTables\Facades\DataTables::of(RelGadget::query())
        ->addColumn('category', fn ($r) => $r->category?->name)
        ->filterColumn('category', fn ($q, $kw) => $q->whereHas('category', fn ($c) => $c->where('name', 'like', "%{$kw}%")))
        ->make(true)
        ->getData(true);

    // If yajra AND-ed the relation filter, "Phon" (no gadget name match) would return nothing.
    // OR semantics → Pixel (category Phones) matches via the relation; Earbuds (Audio) does not.
    $names = collect($json['data'])->pluck('name');
    expect($names)->toContain('Pixel')->not->toContain('Earbuds');
});

it('searches the list by the related name (the generated filterColumn body)', function () {
    $matched = RelGadget::query()
        ->whereHas('category', fn ($rq) => $rq->where('name', 'like', '%Phon%'))
        ->pluck('name');

    expect($matched)->toContain('Pixel')->not->toContain('Earbuds');
});

it('eager-loads the relation via $with so the API list does not N+1', function () {
    // The base ApiController::index does $this->service->query($this->with); prove that path
    // eager-loads (relations resolved in one extra query, not one per row).
    $service = new class(new RelGadget) extends BaseService {
        public function __construct(RelGadget $model)
        {
            $this->model = $model;
        }
    };

    DB::flushQueryLog();
    DB::enableQueryLog();
    $service->query(['category'])->get()->each(fn ($g) => $g->category?->name); // touch the relation

    // 1 query for gadgets + 1 to eager-load their categories = 2 (without $with it would be 1 + N).
    expect(DB::getQueryLog())->toHaveCount(2);
});

it('appends the related name to the CSV export, next to the FK id', function () {
    $service = new class(new RelGadget) extends BaseService {
        public function __construct(RelGadget $model)
        {
            $this->model = $model;
        }
    };
    $controller = new class($service) extends WebController {
        public function __construct($service)
        {
            $this->service = $service;
            $this->routeBase = 'gadgets.';
            $this->exportRelations = ['category'];
        }
    };

    $response = $controller->export();
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    // The header carries the related name column alongside the FK id, and rows show the category names.
    expect($csv)
        ->toContain('category_id')   // the FK still exported (so the file round-trips on import)
        ->toContain('category')      // …plus a readable name column
        ->toContain('Phones')
        ->toContain('Audio');
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
