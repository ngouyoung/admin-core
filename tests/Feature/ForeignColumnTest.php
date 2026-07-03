<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Http\Controllers\WebController;
use Ngos\AdminCore\Services\BaseService;
use Ngos\AdminCore\Tests\Fixtures\RelCategory;
use Ngos\AdminCore\Tests\Fixtures\RelGadget;
use Ngos\AdminCore\Tests\Fixtures\RelTag;

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

    Schema::create('rel_tags', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('rel_gadget_tag', function (Blueprint $t) {
        $t->unsignedBigInteger('gadget_id');
        $t->unsignedBigInteger('tag_id');
    });

    $phones = RelCategory::create(['name' => 'Phones']);
    $audio = RelCategory::create(['name' => 'Audio']);
    $pixel = RelGadget::create(['name' => 'Pixel', 'category_id' => $phones->id]);
    RelGadget::create(['name' => 'Earbuds', 'category_id' => $audio->id]);
    $red = RelTag::create(['name' => 'red']);
    $new = RelTag::create(['name' => 'new']);
    $pixel->tags()->attach([$red->id, $new->id]);
});

afterEach(function () {
    Schema::dropIfExists('rel_gadget_tag');
    Schema::dropIfExists('rel_tags');
    Schema::dropIfExists('rel_gadgets');
    Schema::dropIfExists('rel_categories');
});

/** A controller over RelGadget exporting both relations, optionally limited to ?columns[]. */
function gadgetExportController(): WebController
{
    $service = new class(new RelGadget) extends BaseService {
        public function __construct(RelGadget $model)
        {
            $this->model = $model;
        }
    };

    return new class($service) extends WebController {
        public function __construct($service)
        {
            $this->service = $service;
            $this->routeBase = 'gadgets.';
            $this->exportRelations = ['category', 'tags'];
        }
    };
}

function exportCsv(WebController $controller, array $query = []): string
{
    $response = $controller->export(\Illuminate\Http\Request::create('/export', 'GET', $query));
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

it('exports belongsTo name and belongsToMany joined names', function () {
    $csv = exportCsv(gadgetExportController());

    // Header carries both relation columns; Pixel's row shows its category and joined tags.
    expect($csv)
        ->toContain('category')->toContain('tags')
        ->toContain('Phones')                 // belongsTo: the related name
        ->toMatch('/"?red, new"?|"?new, red"?/'); // belongsToMany: related names joined
});

it('exports the relation, not a colliding scalar column of the same name (no crash)', function () {
    // A scalar column named exactly like the belongsTo relation ('category' + 'category_id'): $row->category
    // would return the STRING attribute, and ?->name on a string crashed the whole streamed export.
    Schema::table('rel_gadgets', fn (Blueprint $t) => $t->string('category')->nullable());
    RelGadget::query()->update(['category' => 'RAW-SCALAR']);

    $csv = exportCsv(gadgetExportController());

    // The relation resolved to its related model's name (not the scalar string, and not a crash).
    expect($csv)->toContain('Phones')->toContain('Audio');
});

it('exports only the chosen columns via ?columns[] (field picker), whitelisted', function () {
    $csv = trim(preg_replace('/^\xEF\xBB\xBF/', '', exportCsv(gadgetExportController(), ['columns' => ['name', 'tags']])));
    $header = strtok($csv, "\n");

    // Only the requested name + tags columns; category (not requested) and id are excluded.
    expect($header)->toBe('name,tags')
        ->and($csv)->toContain('Pixel');
});

it('still writes the column header when the table is empty (streamed lazily, no row needed)', function () {
    RelGadget::query()->delete();
    $csv = trim(preg_replace('/^\xEF\xBB\xBF/', '', exportCsv(gadgetExportController())));

    // The header is derived from the schema, not a fetched row, so an empty export is not blank.
    expect($csv)->toBe('id,name,category_id,category,tags');
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
    $csv = exportCsv(gadgetExportController());

    // The header carries the related name column alongside the FK id, and rows show the category names.
    expect($csv)
        ->toContain('category_id')   // the FK still exported (so the file round-trips on import)
        ->toContain('category')      // …plus a readable name column
        ->toContain('Phones')
        ->toContain('Audio');
});

it('ac_fk_option keeps a SOFT-DELETED parent as the selected option (so the next save cannot null the FK)', function () {
    Schema::create('sd_cats', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->softDeletes();
    });
    Schema::create('sd_items', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('cat_id')->nullable();
    });

    $catModel = new class extends \Illuminate\Database\Eloquent\Model {
        use \Illuminate\Database\Eloquent\SoftDeletes;

        protected $table = 'sd_cats';
        protected $guarded = [];
        public $timestamps = false;
    };
    $itemModel = new class extends \Illuminate\Database\Eloquent\Model {
        protected $table = 'sd_items';
        protected $guarded = [];
        public $timestamps = false;

        public function cat(): \Illuminate\Database\Eloquent\Relations\BelongsTo
        {
            return $this->belongsTo(get_class(new class extends \Illuminate\Database\Eloquent\Model {
                use \Illuminate\Database\Eloquent\SoftDeletes;

                protected $table = 'sd_cats';
                public $timestamps = false;
            }), 'cat_id');
        }
    };

    $cat = $catModel->create(['name' => 'Snacks']);
    $item = $itemModel->create(['cat_id' => $cat->id]);
    $cat->delete(); // the parent is soft-deleted while the item still references it

    // The default relation resolves to null for a trashed parent — ac_fk_option must still return the option
    // so the edit-form select keeps 'Snacks' selected (else the next save posts empty and NULLs cat_id).
    expect($item->fresh()->cat)->toBeNull();                              // precondition: default scope hides it
    expect(ac_fk_option($item->fresh(), 'cat', 'cat_id'))->toBe([$cat->id => 'Snacks']);

    // A genuinely null FK → no option (not a crash).
    $orphan = $itemModel->create(['cat_id' => null]);
    expect(ac_fk_option($orphan, 'cat', 'cat_id'))->toBe([]);

    Schema::dropIfExists('sd_items');
    Schema::dropIfExists('sd_cats');
});

it('ac_bt_options keeps a SOFT-DELETED attached row selected (so sync cannot silently detach it)', function () {
    Schema::create('sd_tags', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->softDeletes();
    });
    Schema::create('sd_posts', function (Blueprint $t) {
        $t->id();
    });
    Schema::create('sd_post_tag', function (Blueprint $t) {
        $t->unsignedBigInteger('post_id');
        $t->unsignedBigInteger('tag_id');
    });

    $tagTable = new class extends \Illuminate\Database\Eloquent\Model {
        use \Illuminate\Database\Eloquent\SoftDeletes;

        protected $table = 'sd_tags';
        protected $guarded = [];
        public $timestamps = false;
    };
    $postModel = new class extends \Illuminate\Database\Eloquent\Model {
        protected $table = 'sd_posts';
        protected $guarded = [];
        public $timestamps = false;

        public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
        {
            return $this->belongsToMany(get_class(new class extends \Illuminate\Database\Eloquent\Model {
                use \Illuminate\Database\Eloquent\SoftDeletes;

                protected $table = 'sd_tags';
                public $timestamps = false;
            }), 'sd_post_tag', 'post_id', 'tag_id');
        }
    };

    $kept = $tagTable->create(['name' => 'kept']);
    $gone = $tagTable->create(['name' => 'gone']);
    $post = $postModel->create([]);
    $post->tags()->attach([$kept->id, $gone->id]);
    $gone->delete(); // soft-delete an ATTACHED tag (pivot row untouched)

    // Default relation drops the trashed tag; ac_bt_options must keep BOTH so the option (and its posted id)
    // survives — otherwise sync([kept]) detaches 'gone'.
    expect($post->fresh()->tags)->toHaveCount(1);            // precondition: default scope hides 'gone'
    $opts = ac_bt_options($post->fresh(), 'tags');
    expect($opts)->toHaveKey($kept->id)->toHaveKey($gone->id) // both present → both posted → sync keeps both
        ->and($opts[$gone->id])->toBe('gone');

    Schema::dropIfExists('sd_post_tag');
    Schema::dropIfExists('sd_posts');
    Schema::dropIfExists('sd_tags');
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
