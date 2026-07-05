<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\Widget;

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('secret')->nullable();
        $table->integer('sort')->default(0);
        $table->timestamps();
    });
});

/** A store request whose hashed `secret` column is REQUIRED — the generated password-field shape. */
class StoreSecretRequiredRequest extends \Illuminate\Foundation\Http\FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'secret' => ['required', 'string', 'min:8'],
        ];
    }
}

it('stores a record and redirects to index with a success flash', function () {
    $this->post('/admin/widgets', ['name' => 'Alpha'])
        ->assertRedirect(route('admin.widgets.index'))
        ->assertSessionHas('success', 'Created successfully.'); // user sees confirmation, not a silent redirect

    expect(Widget::where('name', 'Alpha')->exists())->toBeTrue();
});

it('short-circuits a duplicate create submit carrying the same idempotency token', function () {
    cache()->flush();
    $payload = ['name' => 'Once', '_idempotency_key' => (string) \Illuminate\Support\Str::uuid()];

    $this->post('/admin/widgets', $payload)->assertRedirect();
    $this->post('/admin/widgets', $payload)->assertRedirect(); // double submit, same token

    expect(Widget::where('name', 'Once')->count())->toBe(1); // created once, not twice
});

it('creates normally when no idempotency token is present (backward-compatible)', function () {
    $this->post('/admin/widgets', ['name' => 'NoToken'])->assertRedirect();

    expect(Widget::where('name', 'NoToken')->count())->toBe(1);
});

it('releases the token after a failed create so a retry with the same token succeeds', function () {
    cache()->flush();
    $token = (string) \Illuminate\Support\Str::uuid();

    // Make the first create throw; store() must release the token on failure so the retry is not blocked.
    $failOnce = true;
    Widget::creating(function () use (&$failOnce) {
        if ($failOnce) {
            $failOnce = false;
            throw new \RuntimeException('boom');
        }
    });

    $this->post('/admin/widgets', ['name' => 'Retry', '_idempotency_key' => $token]); // first: throws → token released
    $this->post('/admin/widgets', ['name' => 'Retry', '_idempotency_key' => $token])->assertRedirect(); // retry creates

    expect(Widget::where('name', 'Retry')->count())->toBe(1);
});

it('validates store input', function () {
    $this->post('/admin/widgets', ['name' => ''])->assertSessionHasErrors('name');

    expect(Widget::count())->toBe(0);
});

it('updates a record', function () {
    $widget = Widget::create(['name' => 'Old']);

    $this->put("/admin/widgets/update/{$widget->id}", ['name' => 'New'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($widget->fresh()->name)->toBe('New');
});

it('deletes a record', function () {
    $widget = Widget::create(['name' => 'Bye']);

    $this->delete("/admin/widgets/delete/{$widget->id}")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Widget::find($widget->id))->toBeNull();
});

it('ajax-deletes a record', function () {
    $widget = Widget::create(['name' => 'X']);

    $this->deleteJson("/admin/widgets/ajaxDelete/{$widget->id}")
        ->assertOk()
        ->assertJson(['code' => 200]);

    expect(Widget::find($widget->id))->toBeNull();
});

it('returns datatable json from getData', function () {
    Widget::create(['name' => 'Listme']);

    $this->getJson('/admin/widgets/getData')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Listme']);
});

it('caps the DataTables page length so ?length=-1 (show all) cannot fetch the whole table', function () {
    config()->set('admin-core.pagination_max', 5);
    for ($i = 0; $i < 12; $i++) {
        Widget::create(['name' => "W{$i}"]);
    }

    // length=-1 ("show all") is capped to pagination_max (5), not the full 12 rows.
    $json = $this->getJson('/admin/widgets/getData?draw=1&start=0&length=-1')->assertOk()->json();
    expect(count($json['data']))->toBe(5)
        ->and($json['recordsTotal'])->toBe(12); // the total is still reported; only the page is bounded

    // A huge explicit length is capped too.
    expect(count($this->getJson('/admin/widgets/getData?draw=1&start=0&length=999999')->json('data')))->toBe(5);

    // An ordinary page size is untouched.
    expect(count($this->getJson('/admin/widgets/getData?draw=1&start=0&length=3')->json('data')))->toBe(3);
});

it('returns select2-shaped {id,text} results from the remote select source, filtered by the term', function () {
    Widget::create(['name' => 'Apple']);
    Widget::create(['name' => 'Banana']);

    $this->getJson('/admin/widgets/select?term=app')
        ->assertOk()
        ->assertJsonStructure(['results' => [['id', 'text']], 'pagination' => ['more']])
        ->assertJsonFragment(['text' => 'Apple'])      // matches the term
        ->assertJsonMissing(['text' => 'Banana']);     // filtered out
});

it('paginates the remote select source (more=true while another page exists)', function () {
    foreach (range(1, 45) as $i) {
        Widget::create(['name' => 'W' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
    }

    // Default page size is 20 (config admin-core.select.per_page), so 45 rows → more pages remain.
    $this->getJson('/admin/widgets/select')
        ->assertOk()
        ->assertJsonPath('pagination.more', true)
        ->assertJsonCount(20, 'results');
});

it('orders the unsearched select by primary key (cheap), but by label once a term narrows it', function () {
    Widget::create(['name' => 'Zebra']);  // id 1
    Widget::create(['name' => 'Apple']);  // id 2

    // Empty open: primary-key order — an index range scan + LIMIT, not a filesort over the whole table —
    // so the lowest id comes first, NOT the alphabetically-first row.
    $this->getJson('/admin/widgets/select')
        ->assertOk()
        ->assertJsonPath('results.0.text', 'Zebra')
        ->assertJsonPath('results.1.text', 'Apple');

    // With a term the set is already narrow, so it sorts by the human label (alphabetical).
    $this->getJson('/admin/widgets/select?term=e') // both 'Zebra' and 'Apple' contain "e"
        ->assertOk()
        ->assertJsonPath('results.0.text', 'Apple')
        ->assertJsonPath('results.1.text', 'Zebra');
});

it('narrows the remote select by an allowlisted parent filter (cascading), ignoring others', function () {
    Widget::create(['name' => 'Apple'])->forceFill(['sort' => 1])->save();
    Widget::create(['name' => 'Banana'])->forceFill(['sort' => 2])->save();

    // a controller that allowlists `sort` as a Select2 filter (what admin-core:make sets to the FK columns)
    $controller = new class(new Ngos\AdminCore\Tests\Fixtures\WidgetService(new Widget)) extends \Ngos\AdminCore\Http\Controllers\WebController {
        protected array $selectFilters = ['sort'];

        public function __construct($service)
        {
            $this->service = $service;
        }
    };
    app()->instance('ac-cascade-controller', $controller);
    \Illuminate\Support\Facades\Route::middleware('web')->get('admin/cascadewidgets/select', fn (\Illuminate\Http\Request $r) => app('ac-cascade-controller')->select($r))->name('admin.cascadewidgets.select');

    // filter[sort]=2 narrows to Banana
    $this->getJson('/admin/cascadewidgets/select?filter[sort]=2')
        ->assertOk()->assertJsonFragment(['text' => 'Banana'])->assertJsonMissing(['text' => 'Apple']);

    // a column NOT in $selectFilters is ignored — both rows returned (no arbitrary-column filtering)
    $this->getJson('/admin/cascadewidgets/select?filter[secret]=zzz')
        ->assertOk()->assertJsonFragment(['text' => 'Apple'])->assertJsonFragment(['text' => 'Banana']);
});

it('bulk-deletes selected records', function () {
    $a = Widget::create(['name' => 'a']);
    $b = Widget::create(['name' => 'b']);

    $this->post('/admin/widgets/bulkDelete', ['ids' => [$a->id, $b->id]])->assertOk();

    expect(Widget::count())->toBe(0);
});

it('bulk-deletes resiliently: a stale id is skipped, not a 404 that aborts the whole batch', function () {
    $a = Widget::create(['name' => 'a']);

    // One real id + one that no longer exists. The old per-id firstOrFail() 404'd the entire request.
    $this->post('/admin/widgets/bulkDelete', ['ids' => [$a->id, 999999]])
        ->assertOk()
        ->assertJson(['deleted' => 1]); // reports what was actually deleted

    expect(Widget::count())->toBe(0);
});

it('rejects an oversized bulk id payload (DoS / mass-write cap)', function () {
    // Bulk actions are posted by the DataTable via XHR (JSON), so a failed cap returns 422.
    $this->postJson('/admin/widgets/bulkDelete', ['ids' => range(1, 1001)])
        ->assertStatus(422);
});

it('runs the store form prepareForValidation on imported rows (a CSV cannot bypass sanitisation → XSS)', function () {
    $controller = new class(new Ngos\AdminCore\Tests\Fixtures\WidgetService(new Widget)) extends \Ngos\AdminCore\Http\Controllers\WebController {
        public function __construct($service)
        {
            $this->service = $service;
            $this->routeBase = 'sanwidgets.';
            $this->storeRequest = \Ngos\AdminCore\Tests\Fixtures\StoreWidgetSanitizeRequest::class;
        }
    };
    app()->instance('ac-san-controller', $controller);
    \Illuminate\Support\Facades\Route::middleware('web')->post('admin/sanwidgets/import', fn (\Illuminate\Http\Request $r) => app('ac-san-controller')->import($r))->name('admin.sanwidgets.import');

    $csv = "name\n\"<script>alert(1)</script>Hello\"\n";
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', $csv);

    $this->post('admin/sanwidgets/import', ['file' => $file])->assertRedirect();

    $widget = Widget::first();
    expect($widget)->not->toBeNull()
        ->and($widget->name)->not->toContain('<script>'); // Html::clean ran via prepareForValidation
});

it('exports a csv', function () {
    Widget::create(['name' => 'Export Me']);

    $response = $this->get('/admin/widgets/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    // Leads with a UTF-8 BOM so Excel reads accented/non-ASCII text correctly.
    expect($response->streamedContent())->toStartWith("\xEF\xBB\xBF");
});

it('streams the export with a keyset cursor (lazyById), never OFFSET pagination', function () {
    // lazy()'s OFFSET pagination re-scans every skipped row per chunk — superlinear on a big table —
    // and skips/duplicates rows when another request inserts/deletes mid-export. The export must
    // cursor by id (WHERE id > last ORDER BY id), which stays linear and stable.
    Widget::create(['name' => 'Row A']);
    Widget::create(['name' => 'Row B']);

    $queries = [];
    \Illuminate\Support\Facades\DB::listen(function ($q) use (&$queries) {
        if (str_contains($q->sql, 'widgets')) {
            $queries[] = $q->sql;
        }
    });

    $content = $this->get('/admin/widgets/export')->streamedContent();

    // Both rows exported, and the row-select is id-cursor-ordered, never OFFSET-paginated.
    expect($content)->toContain('Row A')->toContain('Row B');
    $rowQueries = array_values(array_filter($queries, fn ($sql) => str_starts_with($sql, 'select * from "widgets"')));
    expect($rowQueries)->not->toBeEmpty()
        ->and(implode(' ', $rowQueries))->not->toContain('offset')
        ->and($rowQueries[0])->toContain('order by "id" asc');
});

it('downloads a blank import template of the importable columns (no hashed/secret)', function () {
    $response = $this->get(route('admin.widgets.importTemplate'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = trim(preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent())); // strip BOM
    // Header row of fillable columns so the user knows what to fill; the hashed `secret` is excluded.
    expect($csv)->toBe('name,status,photo')
        ->and($csv)->not->toContain('secret'); // never expose the hashed column, even as a header
});

it('imports rows from a csv', function () {
    $csv = "name\nImported A\nImported B\n";
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', $csv);

    $this->post('/admin/widgets/import', ['file' => $file])->assertRedirect();

    expect(Widget::where('name', 'Imported A')->exists())->toBeTrue();
    expect(Widget::where('name', 'Imported B')->exists())->toBeTrue();
});

it('skips invalid rows on import, importing the valid ones', function () {
    // Second row's name exceeds max:255 → skipped; the valid row still imports.
    $csv = "name\nGood\n" . str_repeat('x', 300) . "\n";
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', $csv);

    // A partial import flashes 'warning' (not a green 'success'), naming what was skipped.
    $this->post('/admin/widgets/import', ['file' => $file])
        ->assertRedirect()
        ->assertSessionMissing('success')
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'Imported 1') && str_contains($m, 'Skipped 1'));

    expect(Widget::count())->toBe(1);
    expect(Widget::where('name', 'Good')->exists())->toBeTrue();
});

it('flashes an error (not success) when an import brings in nothing', function () {
    $csv = "name\n" . str_repeat('x', 300) . "\n"; // the only row is invalid
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', $csv);

    $this->post('/admin/widgets/import', ['file' => $file])
        ->assertRedirect()
        ->assertSessionMissing('success')
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Imported 0'));

    expect(Widget::count())->toBe(0);
});

it('imports the app\'s own template for a resource with a REQUIRED hashed (password) field', function () {
    // export()/importTemplate() exclude hashed columns by design — but import() validated against the raw
    // store rules, so `secret => required` rejected EVERY row of the app's own template ("Imported 0").
    // Absent from the CSV → the rule softens to `sometimes` (not validated, not imported).
    $controller = new class(app(\Ngos\AdminCore\Tests\Fixtures\WidgetService::class)) extends \Ngos\AdminCore\Tests\Fixtures\WidgetController {
        public function __construct(\Ngos\AdminCore\Tests\Fixtures\WidgetService $service)
        {
            parent::__construct($service);
            $this->storeRequest = StoreSecretRequiredRequest::class;
        }
    };
    \Illuminate\Support\Facades\Route::middleware('web')
        ->post('/admin/secret-widgets/import', [$controller::class, 'import']);

    $csv = "name\nAlice\n"; // exactly the shape importTemplate() hands the user (no secret column)
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', $csv);

    $this->post('/admin/secret-widgets/import', ['file' => $file])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'Imported 1'));
    expect(Widget::where('name', 'Alice')->exists())->toBeTrue();

    // A CSV that DOES carry the column is still validated in full (min:8 fails → skipped, not stored).
    $bad = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', "name,secret\nBob,short\n");
    $this->post('/admin/secret-widgets/import', ['file' => $bad])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Imported 0'));
    expect(Widget::where('name', 'Bob')->exists())->toBeFalse();

    // And a valid supplied secret imports hashed (the model's cast), not as plaintext.
    $ok = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', "name,secret\nCara,supersecret\n");
    $this->post('/admin/secret-widgets/import', ['file' => $ok])->assertSessionHas('success');
    $cara = Widget::where('name', 'Cara')->first();
    expect($cara)->not->toBeNull()
        ->and($cara->getRawOriginal('secret'))->not->toBe('supersecret') // stored hashed
        ->and(\Illuminate\Support\Facades\Hash::check('supersecret', $cara->getRawOriginal('secret')))->toBeTrue();
});

it('reports a database-refused import row as skipped instead of aborting the import', function () {
    // A NOT NULL column the CSV can't carry (or a unique race / FK violation) must skip THAT row and
    // keep going — not 500 a half-finished import.
    Schema::drop('widgets');
    Schema::create('widgets', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('secret'); // NOT NULL — and the CSV has no secret column
        $table->integer('sort')->default(0);
        $table->timestamps();
    });

    $csv = "name\nNoSecret\n";
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', $csv);

    $this->post('/admin/widgets/import', ['file' => $file])
        ->assertRedirect()
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Imported 0') && str_contains($m, 'Row 2'));
    expect(Widget::count())->toBe(0);
});

it('ignores a UTF-8 BOM and non-fillable columns on import (round-trips export)', function () {
    // Mirrors an exported file: BOM + id/created_at columns that aren't fillable.
    $csv = "\xEF\xBB\xBFid,name,created_at\n7,Roundtrip,2026-01-01 00:00:00\n";
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', $csv);

    $this->post('/admin/widgets/import', ['file' => $file])->assertRedirect();

    $w = Widget::where('name', 'Roundtrip')->first();
    expect($w)->not->toBeNull();
    expect($w->id)->not->toBe(7); // the id column was ignored, not forced
});

it('retries a create on a transient deadlock (outermost transaction, so the attempts loop fires)', function () {
    // A sequence-number contention raises a deadlock inside the create; the retry belongs at the OUTERMOST
    // transaction (store's DB::transaction(..., 3)) — nested it's dead code. One transient deadlock must be
    // retried transparently, not surfaced as a failed submission.
    $fired = 0;
    Widget::creating(function () use (&$fired) {
        $fired++;
        if ($fired === 1) {
            // A concurrency error Laravel's DB::transaction attempts-loop recognises + retries.
            throw new \Illuminate\Database\QueryException(
                'testing', 'insert into "widgets"', [],
                new \RuntimeException('Deadlock found when trying to get lock; try restarting transaction'),
            );
        }
    });

    try {
        $this->post('/admin/widgets', ['name' => 'Retried'])->assertRedirect(route('admin.widgets.index'));

        expect($fired)->toBe(2)                                       // deadlocked once, retried, succeeded
            ->and(Widget::where('name', 'Retried')->exists())->toBeTrue();
    } finally {
        Widget::flushEventListeners(); // don't leak the one-shot listener into other tests
    }
});

it('scopes find() through an overridden query() — the BaseService tenant hook', function () {
    $visible = Widget::create(['name' => 'Visible']);
    $hidden = Widget::create(['name' => 'Hidden']);

    // A service whose query() hides everything but "Visible" — find() must honour it,
    // proving a single query() override (e.g. tenant scoping) covers reads + lookups.
    $service = new class(new Widget) extends \Ngos\AdminCore\Services\BaseService {
        public function __construct(Widget $model)
        {
            $this->model = $model;
        }

        public function query(array|string|null $relation = null): \Illuminate\Database\Eloquent\Builder
        {
            return parent::query($relation)->where('name', 'Visible');
        }
    };

    expect($service->find($visible->getKey())->name)->toBe('Visible');

    $service->find($hidden->getKey()); // out of scope → not found
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('neutralises CSV formula injection on export', function () {
    Widget::create(['name' => '=HYPERLINK("http://evil","clickme")']);

    $content = $this->get('/admin/widgets/export')->streamedContent();

    // The dangerous cell is prefixed with a quote so spreadsheets treat it as text.
    // fputcsv wraps it in double quotes (it has commas), so guarded => "'=HYPERLINK,
    // and the raw, unguarded "=HYPERLINK must not appear.
    expect($content)->toContain('\'=HYPERLINK');
    expect($content)->not->toContain('"=HYPERLINK');
});

it('never exports a password (hashed) column', function () {
    Widget::create(['name' => 'Has Secret', 'secret' => 'topsecret123']);

    $content = $this->get('/admin/widgets/export')->streamedContent();

    // The header must not list the column, and the bcrypt hash must not appear anywhere.
    expect($content)->toContain('name')
        ->not->toContain('secret')
        ->not->toContain('$2y$');
});

it('never ships a hashed column in the getData list JSON (defense-in-depth, matching export)', function () {
    // Widget casts `secret` hashed but has no $hidden (models predating the generated $hidden). getData
    // serializes every row via toArray, so without the strip the bcrypt hash would leak into the list.
    Widget::create(['name' => 'Listme', 'secret' => 'topsecret123']);

    $json = $this->getJson('/admin/widgets/getData')->assertOk()->getContent();

    expect($json)->toContain('Listme')     // the row is there…
        ->not->toContain('secret')         // …but the hashed column key is stripped
        ->not->toContain('$2y$');          // …and the bcrypt hash never appears
});

it('encodes array/enum values for CSV export instead of writing "Array"', function () {
    // csvCell turns a json/array-cast attribute into a JSON string (fputcsv would otherwise
    // emit a literal "Array" + a PHP warning); enums export their backing value.
    $exporter = new class extends \Ngos\AdminCore\Http\Controllers\WebController {
        public function cell(mixed $v): mixed
        {
            return $this->csvCell($v);
        }
    };

    expect($exporter->cell(['k' => 'v', 'n' => 2]))->toBe('{"k":"v","n":2}')
        ->and($exporter->cell([]))->toBe('[]')
        ->and($exporter->cell('plain'))->toBe('plain')
        ->and($exporter->cell(null))->toBeNull()
        // Booleans export as 1/0 (not ''), so a false round-trips through the import boolean rule.
        ->and($exporter->cell(true))->toBe('1')
        ->and($exporter->cell(false))->toBe('0');
});

it('drops image/file columns on import (a CSV cannot carry a file) and still imports the row', function () {
    // A controller whose store rules include an image column, routed for import.
    $controller = new class(new Ngos\AdminCore\Tests\Fixtures\WidgetService(new Widget)) extends \Ngos\AdminCore\Http\Controllers\WebController {
        public function __construct($service)
        {
            $this->service = $service;
            $this->routeBase = 'imgwidgets.';
            $this->storeRequest = \Ngos\AdminCore\Tests\Fixtures\StoreWidgetImageRequest::class;
        }
    };
    app()->instance('ac-img-controller', $controller);
    \Illuminate\Support\Facades\Route::middleware('web')->post('admin/imgwidgets/import', fn (\Illuminate\Http\Request $r) => app('ac-img-controller')->import($r))->name('admin.imgwidgets.import');

    // Exported shape: a row carrying a stored image PATH (not a file). Without the fix the `image`
    // rule rejects the path and the row is skipped; with it, the column is dropped and the row imports.
    $csv = "name,photo\nAlpha,products/old-pic.jpg\n";
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', $csv);

    $this->post('admin/imgwidgets/import', ['file' => $file])->assertRedirect();

    // The row imported (the image path didn't fail validation and skip it); photo wasn't written.
    expect(Widget::where('name', 'Alpha')->exists())->toBeTrue();
});

it('drops image/file columns on import in the pipe-string rule form too', function () {
    // Same as above but the FormRequest declares 'nullable|image|max:2048' (string form) — equally valid
    // Laravel, and the exported-path round-trip must not be rejected for it.
    $controller = new class(new Ngos\AdminCore\Tests\Fixtures\WidgetService(new Widget)) extends \Ngos\AdminCore\Http\Controllers\WebController {
        public function __construct($service)
        {
            $this->service = $service;
            $this->routeBase = 'pipewidgets.';
            $this->storeRequest = \Ngos\AdminCore\Tests\Fixtures\StoreWidgetImagePipeRequest::class;
        }
    };
    app()->instance('ac-pipe-controller', $controller);
    \Illuminate\Support\Facades\Route::middleware('web')->post('admin/pipewidgets/import', fn (\Illuminate\Http\Request $r) => app('ac-pipe-controller')->import($r))->name('admin.pipewidgets.import');

    $csv = "name,photo\nBeta,products/old-pic.jpg\n";
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', $csv);

    $this->post('admin/pipewidgets/import', ['file' => $file])->assertRedirect();

    expect(Widget::where('name', 'Beta')->exists())->toBeTrue();
});

it('reorders records by sort position', function () {
    $a = Widget::create(['name' => 'a']);
    $b = Widget::create(['name' => 'b']);
    $c = Widget::create(['name' => 'c']);

    $this->post('/admin/widgets/reorder', ['ids' => [$c->id, $a->id, $b->id]])
        ->assertOk()
        ->assertJson(['code' => 200]);

    expect($c->fresh()->sort)->toBe(1);
    expect($a->fresh()->sort)->toBe(2);
    expect($b->fresh()->sort)->toBe(3);
});
