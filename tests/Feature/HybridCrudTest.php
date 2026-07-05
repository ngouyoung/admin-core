<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\HybridWidget;

/**
 * End-to-end CRUD over HTTP for a hybrid-key resource (bigint id + public uuid).
 * This is the generator's default key strategy, and the path where the edit/delete
 * bugs lived — addressing a record by its uuid route key, resolved in BaseService.
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

it('heals a legacy null-uuid row on its next save (fills on `saving`, not just `creating`)', function () {
    // A row that predates the retrofitted uuid column has uuid=NULL; the old creating-only hook never
    // filled it on an update, so it stayed NULL forever (and 500'd route('…edit', null)). `saving` heals it.
    Schema::dropIfExists('legacy_uuids');
    Schema::create('legacy_uuids', function (Blueprint $t) {
        $t->id();
        $t->uuid('uuid')->nullable()->unique();
        $t->string('name');
    });
    \Illuminate\Support\Facades\DB::table('legacy_uuids')->insert(['name' => 'Legacy']); // raw, no Eloquent event

    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        use \Ngos\AdminCore\Concerns\HasPublicUuid;

        protected $table = 'legacy_uuids';

        public $timestamps = false;

        protected $guarded = [];
    };
    $row = $model->newQuery()->first();
    expect($row->uuid)->toBeNull();

    $row->name = 'Updated';
    $row->save(); // an UPDATE — `creating` would not fire here, but `saving` does

    expect($row->fresh()->uuid)->not->toBeNull();
    Schema::dropIfExists('legacy_uuids');
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
