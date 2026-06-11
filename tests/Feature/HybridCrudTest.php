<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\HybridWidget;

/**
 * End-to-end CRUD over HTTP for a hybrid-key resource (bigint id + public uuid).
 * This is the generator's default key strategy, and the path where the edit/delete
 * bugs lived — addressing a record by its uuid route key, resolved in CrudService.
 */
beforeEach(function () {
    Schema::dropIfExists('hybrid_widgets');
    Schema::create('hybrid_widgets', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('name');
        $table->timestamps();
    });
});

it('fills a uuid on create and exposes it as the route key', function () {
    $w = HybridWidget::create(['name' => 'Alpha']);

    expect($w->uuid)->not->toBeEmpty();
    expect($w->getRouteKey())->toBe($w->uuid);
});

it('updates a hybrid record addressed by its uuid (not the bigint id)', function () {
    $w = HybridWidget::create(['name' => 'Old']);

    $this->put("/admin/hybrid-widgets/update/{$w->uuid}", ['name' => 'New'])->assertRedirect();

    expect($w->fresh()->name)->toBe('New');
});

it('deletes and ajax-deletes a hybrid record by uuid', function () {
    $w = HybridWidget::create(['name' => 'Bye']);
    $this->delete("/admin/hybrid-widgets/delete/{$w->uuid}")->assertRedirect();
    expect(HybridWidget::count())->toBe(0);

    $w2 = HybridWidget::create(['name' => 'X']);
    $this->deleteJson("/admin/hybrid-widgets/ajaxDelete/{$w2->uuid}")
        ->assertOk()
        ->assertJson(['code' => 200]);
    expect(HybridWidget::count())->toBe(0);
});

it('returns the uuid in the datatable json', function () {
    $w = HybridWidget::create(['name' => 'Listme']);

    $this->getJson('/admin/hybrid-widgets/getData')
        ->assertOk()
        ->assertJsonFragment(['uuid' => $w->uuid]);
});

it('does not resolve a hybrid record by its bigint id', function () {
    // The whole point of the strategy: the auto-increment id is not a public handle.
    // Addressing the record by id (instead of uuid) must not find it.
    $w = HybridWidget::create(['name' => 'Old']);

    $this->put("/admin/hybrid-widgets/update/{$w->id}", ['name' => 'New'])->assertNotFound();

    expect($w->fresh()->name)->toBe('Old');
});
