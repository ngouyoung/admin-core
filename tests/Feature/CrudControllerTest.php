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

it('bulk-deletes selected records', function () {
    $a = Widget::create(['name' => 'a']);
    $b = Widget::create(['name' => 'b']);

    $this->post('/admin/widgets/bulkDelete', ['ids' => [$a->id, $b->id]])->assertOk();

    expect(Widget::count())->toBe(0);
});

it('exports a csv', function () {
    Widget::create(['name' => 'Export Me']);

    $response = $this->get('/admin/widgets/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    // Leads with a UTF-8 BOM so Excel reads accented/non-ASCII text correctly.
    expect($response->streamedContent())->toStartWith("\xEF\xBB\xBF");
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

    $this->post('/admin/widgets/import', ['file' => $file])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Widget::count())->toBe(1);
    expect(Widget::where('name', 'Good')->exists())->toBeTrue();
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
        ->and($exporter->cell(null))->toBeNull();
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
