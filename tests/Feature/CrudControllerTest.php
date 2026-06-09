<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\Widget;

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
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
});
