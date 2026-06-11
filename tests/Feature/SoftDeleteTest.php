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
