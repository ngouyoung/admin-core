<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Support\Search;
use Ngos\AdminCore\Tests\Fixtures\Widget;

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Widget::create(['name' => 'Alpha Coffee']);
    Widget::create(['name' => 'Beta Tea']);

    config(['admin-core.search' => [
        ['model' => Widget::class, 'columns' => ['name'], 'label' => 'Widgets', 'icon' => 'bi bi-box'],
    ]]);
});

it('returns LIKE matches, grouped, with label + icon', function () {
    $results = Search::query('alpha');

    expect($results)->toHaveCount(1)
        ->and($results[0]['label'])->toBe('Alpha Coffee')
        ->and($results[0]['group'])->toBe('Widgets')
        ->and($results[0]['icon'])->toBe('bi bi-box')
        ->and($results[0]['url'])->toBeNull(); // no route configured -> no link
});

it('matches case-insensitively across rows and caps per group', function () {
    expect(Search::query('e'))->toHaveCount(2);          // both names contain "e"
    expect(Search::query('alpha', perGroup: 0))->toHaveCount(0); // cap respected
});

it('treats a LIKE metacharacter in the term LITERALLY (underscore is not a single-char wildcard)', function () {
    Widget::create(['name' => 'aXc gadget']);  // would match the pattern %a_c% if _ were a wildcard
    Widget::create(['name' => 'a_c literal']); // the literal underscore the user typed

    // Underscore must match ONLY a literal underscore — not any single char.
    $results = collect(Search::query('a_c'))->pluck('label');
    expect($results)->toContain('a_c literal')   // the literal match
        ->not->toContain('aXc gadget');           // NOT the wildcard collision

    // A percent is literal too — searching '%' matches nothing (no name contains a literal %).
    expect(Search::query('%'))->toHaveCount(0);
});

it('returns nothing for a blank term or when no resources are configured', function () {
    expect(Search::query(''))->toBe([]);

    config(['admin-core.search' => []]);
    expect(Search::query('alpha'))->toBe([]);
});

it('runs through the configured Service query() so its scope constrains search (no cross-tenant leak)', function () {
    Widget::create(['name' => 'Alpha Other Tenant']); // a row another tenant owns

    // A Service whose query() is scoped (as a multi-tenant base Service would be) — here: only 'Coffee' rows.
    $service = new class(new Widget) extends \Ngos\AdminCore\Services\BaseService {
        public function __construct(Widget $model)
        {
            $this->model = $model;
        }

        public function query(array|string|null $relation = null): \Illuminate\Database\Eloquent\Builder
        {
            return Widget::query()->where('name', 'like', '%Coffee%'); // the tenant/authz scope
        }
    };
    app()->instance('ac-search-svc', $service);

    config(['admin-core.search' => [
        ['model' => Widget::class, 'service' => 'ac-search-svc', 'columns' => ['name'], 'label' => 'Widgets'],
    ]]);

    // 'Alpha' matches BOTH 'Alpha Coffee' and 'Alpha Other Tenant', but the Service scope excludes the latter.
    $results = Search::query('Alpha');
    expect(collect($results)->pluck('label')->all())->toBe(['Alpha Coffee']); // scoped: the other-tenant row is not leaked
});

// -- Permission gate: guard-aware + fail-safe -------------------------------------------------------

it('fails safe — with permission enabled but no resolvable user, returns nothing (no leak)', function () {
    config()->set('admin-core.permission.enabled', true);
    // No authenticated user. The entry needs 'list-widget'; the gate must DENY, not return everything.
    expect(Search::query('alpha'))->toBe([]);
});

it('returns results once the user holds the list permission', function () {
    config()->set('admin-core.permission.enabled', true);
    \Illuminate\Support\Facades\Gate::define('list-widget', fn () => true);
    test()->actingAs(new \Ngos\AdminCore\Tests\Fixtures\NotifiableUser(['name' => 'U']));

    expect(Search::query('alpha'))->toHaveCount(1);
});

it('resolves the user on the given PORTAL guard, not the default guard (multi-portal)', function () {
    config()->set('admin-core.permission.enabled', true);
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);
    \Illuminate\Support\Facades\Gate::define('list-widget', fn () => true);
    // Authenticate ONLY on the merchant guard, WITHOUT switching the default guard (so `auth()->user()` on the
    // default 'web' guard stays null — the real multi-portal shape). actingAs() would shouldUse('merchant').
    auth()->guard('merchant')->setUser(new \Ngos\AdminCore\Tests\Fixtures\NotifiableUser(['name' => 'M']));

    // Default-guard lookup finds no user → fail-safe → nothing (the old bug leaked here).
    expect(Search::query('alpha'))->toBe([]);
    // Guard-aware lookup finds the merchant user → results.
    expect(Search::query('alpha', guard: 'merchant'))->toHaveCount(1);
});

it('exposes a shared escaped-LIKE helper every search path routes through', function () {
    // The systemic fix: one helper so LIKE-metacharacter escaping can't drift out of a single search path again.
    expect(Search::likePattern('a_c%'))->toBe('%a!_c!%%'); // _ and % escaped with the portable "!" sentinel, wrapped in %…%

    Schema::create('wl_items', fn (Blueprint $t) => tap($t)->id()->string('name'));
    $m = new class extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'wl_items';
        public $timestamps = false;
        protected $guarded = [];
    };
    $m::create(['name' => 'a_c']); // the literal
    $m::create(['name' => 'aXc']); // a '_' wildcard would wrongly match this

    $q = $m::query();
    Search::whereLike($q, 'name', 'a_c');
    expect($q->pluck('name')->all())->toBe(['a_c']); // underscore matched literally, not as a wildcard

    // A non-identifier "column" is skipped (never spliced into SQL) — the query is left unfiltered.
    $q2 = $m::query();
    Search::whereLike($q2, 'name`; DROP TABLE wl_items; --', 'x');
    expect($q2->count())->toBe(2);

    Schema::dropIfExists('wl_items');
});

it('matches a translatable (JSON) column in the active locale, not the raw JSON blob', function () {
    Schema::create('trans_widgets', function (Blueprint $t) {
        $t->id();
        $t->json('name');
    });
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'trans_widgets';
        public $timestamps = false;
        protected $guarded = [];
        protected $casts = ['name' => 'array'];
    };
    $model::create(['name' => ['en' => 'Phones', 'km' => 'ទូរស័ព្ទ']]);
    config(['admin-core.search' => [['model' => get_class($model), 'columns' => ['name'], 'label' => 'TW']]]);
    app()->setLocale('en');

    expect(Search::query('Phon'))->toHaveCount(1)    // matches the active-locale value
        ->and(Search::query('xyz'))->toHaveCount(0)  // no false match
        ->and(Search::query('"en"'))->toHaveCount(0); // does NOT match the JSON syntax (not a raw-blob LIKE)

    Schema::dropIfExists('trans_widgets');
});

it('does not let a hostile app locale inject SQL into a JSON-column search', function () {
    Schema::create('trans_widgets', function (Blueprint $t) {
        $t->id();
        $t->json('name');
    });
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'trans_widgets';

        public $timestamps = false;

        protected $guarded = [];

        protected $casts = ['name' => 'array'];
    };
    $model::create(['name' => ['en' => 'Phones']]);
    config(['admin-core.search' => [['model' => get_class($model), 'columns' => ['name'], 'label' => 'TW']]]);

    // app()->getLocale() is globally mutable — a raw-spliced locale like this would break out of the
    // json_extract path and OR-1=1 every row. Bound + sanitised, it can't error or bypass.
    app()->setLocale('en"\')) OR 1=1 -- ');

    expect(fn () => Search::query('zzz-no-match'))->not->toThrow(Throwable::class); // no SQL error / injection
    expect(Search::query('zzz-no-match'))->toHaveCount(0);                          // OR 1=1 did NOT run → no bypass

    Schema::dropIfExists('trans_widgets');
});
