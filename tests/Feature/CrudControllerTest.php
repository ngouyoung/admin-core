<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\Widget;

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->integer('sort')->default(0);
        $table->timestamps();
    });
});

it('stores a record and redirects to index', function () {
    $this->post('/admin/widgets', ['name' => 'Alpha'])
        ->assertRedirect(route('admin.widgets.index'));

    expect(Widget::where('name', 'Alpha')->exists())->toBeTrue();
});

it('validates store input', function () {
    $this->post('/admin/widgets', ['name' => ''])->assertSessionHasErrors('name');

    expect(Widget::count())->toBe(0);
});

it('updates a record', function () {
    $widget = Widget::create(['name' => 'Old']);

    $this->put("/admin/widgets/update/{$widget->id}", ['name' => 'New'])->assertRedirect();

    expect($widget->fresh()->name)->toBe('New');
});

it('deletes a record', function () {
    $widget = Widget::create(['name' => 'Bye']);

    $this->delete("/admin/widgets/delete/{$widget->id}")->assertRedirect();

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

it('neutralises CSV formula injection on export', function () {
    Widget::create(['name' => '=HYPERLINK("http://evil","clickme")']);

    $content = $this->get('/admin/widgets/export')->streamedContent();

    // The dangerous cell is prefixed with a quote so spreadsheets treat it as text.
    // fputcsv wraps it in double quotes (it has commas), so guarded => "'=HYPERLINK,
    // and the raw, unguarded "=HYPERLINK must not appear.
    expect($content)->toContain('\'=HYPERLINK');
    expect($content)->not->toContain('"=HYPERLINK');
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
