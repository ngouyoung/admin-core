<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Services\BaseService;
use Ngos\AdminCore\Tests\Fixtures\SoftWidget;

beforeEach(function () {
    Schema::dropIfExists('soft_widgets');
    Schema::create('soft_widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->softDeletes();
        $table->timestamps();
    });
});

function softService(): BaseService
{
    return new class(new SoftWidget) extends BaseService
    {
        public function __construct(SoftWidget $model)
        {
            $this->model = $model;
        }
    };
}

it('soft-deletes, lists trashed, restores and force-deletes', function () {
    $service = softService();
    $widget = SoftWidget::create(['name' => 'A']);

    $service->delete($widget->id);
    expect(SoftWidget::count())->toBe(0);
    expect($service->trashedQuery()->count())->toBe(1);

    $service->restore($widget->id);
    expect(SoftWidget::count())->toBe(1);

    $service->delete($widget->id);
    $service->forceDelete($widget->id);
    expect($service->trashedQuery()->count())->toBe(0);
});

// A query() override (e.g. a tenant scope) must cover the trash + restore + force-delete paths too,
// exactly as the BaseService docblock promises — not just find/update/delete.
function scopedSoftService(): BaseService
{
    return new class(new SoftWidget) extends BaseService
    {
        public function __construct(SoftWidget $model)
        {
            $this->model = $model;
        }

        public function query(array|string|null $relation = null): \Illuminate\Database\Eloquent\Builder
        {
            return parent::query($relation)->where('name', 'Mine'); // pretend tenant scope
        }
    };
}

it('honours a query() override on the trash listing', function () {
    softService()->delete(SoftWidget::create(['name' => 'Mine'])->id);
    softService()->delete(SoftWidget::create(['name' => 'Theirs'])->id);

    // The scoped service only sees its own trashed row, not the out-of-scope one.
    expect(scopedSoftService()->trashedQuery()->count())->toBe(1)
        ->and(scopedSoftService()->trashedQuery()->first()->name)->toBe('Mine');
});

it('refuses to restore a record outside the query() scope', function () {
    $theirs = SoftWidget::create(['name' => 'Theirs']);
    softService()->delete($theirs->id);

    scopedSoftService()->restore($theirs->id); // out of scope → not found, not restored
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('refuses to force-delete a record outside the query() scope', function () {
    $theirs = SoftWidget::create(['name' => 'Theirs']);
    softService()->delete($theirs->id);

    scopedSoftService()->forceDelete($theirs->id); // out of scope → not found
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('honours a query() override on reorder (cannot touch out-of-scope rows)', function () {
    Schema::table('soft_widgets', fn (Blueprint $t) => $t->integer('sort')->default(0));
    $mine = SoftWidget::create(['name' => 'Mine']);
    $theirs = SoftWidget::create(['name' => 'Theirs']);

    scopedSoftService()->reorder([$theirs->id, $mine->id]);

    expect($mine->fresh()->sort)->toBe(2)   // in scope → written
        ->and($theirs->fresh()->sort)->toBe(0); // out of scope → untouched
});
