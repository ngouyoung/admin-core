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

it('stores a record and redirects to index with a success flash', function () {
    $this->post('/admin/widgets', ['name' => 'Alpha'])
        ->assertRedirect(route('admin.widgets.index'))
        ->assertSessionHas('success', 'Created successfully.'); // user sees confirmation, not a silent redirect

    expect(Widget::where('name', 'Alpha')->exists())->toBeTrue();
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

it('ignores a UTF-8 BOM and non-fillable columns on import (round-trips export)', function () {
    // Mirrors an exported file: BOM + id/created_at columns that aren't fillable.
    $csv = "\xEF\xBB\xBFid,name,created_at\n7,Roundtrip,2026-01-01 00:00:00\n";
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('widgets.csv', $csv);

    $this->post('/admin/widgets/import', ['file' => $file])->assertRedirect();

    $w = Widget::where('name', 'Roundtrip')->first();
    expect($w)->not->toBeNull();
    expect($w->id)->not->toBe(7); // the id column was ignored, not forced
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
