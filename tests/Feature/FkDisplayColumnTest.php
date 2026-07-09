<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Http\Controllers\WebController;
use Ngos\AdminCore\Services\BaseService;
use Ngos\AdminCore\Tests\Fixtures\RelCategory; // name-based (existing fixture)
use Ngos\AdminCore\Tests\Fixtures\TitledCategory; // title-based

/*
 * FI-6 — a related model's FK <select> label + remote search resolve a display column (name → title → label,
 * or an explicit override), instead of assuming `name`. The SAME column drives the current-value prefill
 * (ac_fk_option) and the remote search (WebController::select), so a title-based model (the LMS catalog:
 * Course, Lesson, Document) renders and searches by `title` — and name-based models are unchanged.
 */

beforeEach(function () {
    Schema::dropIfExists('titled_categories');
    Schema::dropIfExists('rel_categories');
    Schema::dropIfExists('fkhosts');
    Schema::create('titled_categories', function (Blueprint $t) {
        $t->id();
        $t->string('title');
    });
    Schema::create('rel_categories', function (Blueprint $t) { // name-based backward-compat baseline
        $t->id();
        $t->string('name');
    });
    Schema::create('fkhosts', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('titled_id')->nullable();
        $t->unsignedBigInteger('named_id')->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('fkhosts');
    Schema::dropIfExists('rel_categories');
    Schema::dropIfExists('titled_categories');
});

/** A host with belongsTo relations to a title-based and a name-based related model. */
function fkHost(): Model
{
    return new class extends Model
    {
        protected $table = 'fkhosts';

        protected $guarded = [];

        public $timestamps = false;

        public function titled(): BelongsTo
        {
            return $this->belongsTo(TitledCategory::class, 'titled_id');
        }

        public function named(): BelongsTo
        {
            return $this->belongsTo(RelCategory::class, 'named_id');
        }
    };
}

/** A remote-select controller over a given model (mirrors the generated resource controller). */
function selectController(Model $model): WebController
{
    $service = new class($model) extends BaseService
    {
        public function __construct(Model $model)
        {
            $this->model = $model;
        }
    };

    return new class($service) extends WebController
    {
        public function __construct(BaseService $service)
        {
            $this->service = $service;
        }
    };
}

// ---- current-value prefill (ac_fk_option) --------------------------------------------------------

it('prefills a title-based FK option with its title, not a null name', function () {
    $cat = TitledCategory::create(['title' => 'Phones']);
    $host = fkHost();
    $host->titled_id = $cat->id;
    $host->save();

    expect(ac_fk_option($host->fresh(), 'titled', 'titled_id'))->toBe([$cat->id => 'Phones']);
});

it('still prefills a name-based FK option with its name (backward compatible)', function () {
    $cat = RelCategory::create(['name' => 'Audio']);
    $host = fkHost();
    $host->named_id = $cat->id;
    $host->save();

    expect(ac_fk_option($host->fresh(), 'named', 'named_id'))->toBe([$cat->id => 'Audio']);
});

// ---- remote search (WebController::select) -------------------------------------------------------

it('labels + searches a title-based remote select by title', function () {
    TitledCategory::create(['title' => 'Phones']);
    TitledCategory::create(['title' => 'Audio']);

    $res = selectController(new TitledCategory)->select(new \Illuminate\Http\Request(['term' => 'Pho']));
    $data = $res->getData(true)['results'];

    expect($data)->toHaveCount(1)
        ->and($data[0]['text'])->toBe('Phones');
});

it('labels + searches a name-based remote select by name (backward compatible)', function () {
    RelCategory::create(['name' => 'Phones']);
    RelCategory::create(['name' => 'Audio']);

    $res = selectController(new RelCategory)->select(new \Illuminate\Http\Request(['term' => 'Aud']));
    $data = $res->getData(true)['results'];

    expect($data)->toHaveCount(1)
        ->and($data[0]['text'])->toBe('Audio');
});

// ---- prefill + search agree on the SAME column ---------------------------------------------------

it('resolves the SAME display column for prefill and remote search', function () {
    $cat = TitledCategory::create(['title' => 'Phones']);
    $host = fkHost();
    $host->titled_id = $cat->id;
    $host->save();

    $prefill = ac_fk_option($host->fresh(), 'titled', 'titled_id')[$cat->id];
    $search = selectController(new TitledCategory)->select(new \Illuminate\Http\Request(['term' => 'Phones']))
        ->getData(true)['results'][0]['text'];

    expect($prefill)->toBe('Phones')->and($search)->toBe('Phones'); // never one by title, the other by name
});

// ---- adversarial precedence / fallback (FI-6 contract) ------------------------------------------

it('resolves `label` when only a label column exists', function () {
    Schema::create('adv_labeled', function (Blueprint $t) {
        $t->id();
        $t->string('label');
    });
    $m = new class extends Model
    {
        protected $table = 'adv_labeled';

        protected $guarded = [];

        public $timestamps = false;
    };
    expect(ac_display_column($m))->toBe('label');
    Schema::dropIfExists('adv_labeled');
});

it('prefers `name` over `title` when both exist (deterministic, backward compatible)', function () {
    Schema::create('adv_dual', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('title');
    });
    $m = new class extends Model
    {
        protected $table = 'adv_dual';

        protected $guarded = [];

        public $timestamps = false;
    };
    expect(ac_display_column($m))->toBe('name');
    Schema::dropIfExists('adv_dual');
});

it('lets an explicit model displayColumn() override win over the column probe', function () {
    Schema::create('adv_override', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('title');
    });
    $m = new class extends Model
    {
        protected $table = 'adv_override';

        protected $guarded = [];

        public $timestamps = false;

        public function displayColumn(): string
        {
            return 'title';
        }
    };
    expect(ac_display_column($m))->toBe('title'); // override beats the name-first probe
    Schema::dropIfExists('adv_override');
});

it('falls back to the route key (a real column) when no name/title/label exists — no SQL error', function () {
    Schema::create('adv_keyed', function (Blueprint $t) {
        $t->id();
        $t->string('slug');
    });
    $m = new class extends Model
    {
        protected $table = 'adv_keyed';

        protected $guarded = [];

        public $timestamps = false;
    };
    expect(ac_display_column($m))->toBe($m->getRouteKeyName()); // 'id' — always a real column

    // the remote search must NOT throw a "no such column: name" — it queries the real fallback column
    $m->newInstance(['slug' => 'x'])->save();
    $res = selectController($m)->select(new \Illuminate\Http\Request(['term' => '1']));
    expect($res->getStatusCode())->toBe(200);
    Schema::dropIfExists('adv_keyed');
});
