<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;
use Ngos\AdminCore\Tests\Fixtures\Widget;

/*
 * CSV import must apply the SAME write guards as the create form: strip a field the user may not write
 * (fieldPermissions) and strip the transition-only state column — a CSV must not be a back door around them.
 * (ActionWidgetController declares fieldPermissions[secret => edit-secret-action-widget], transitions(),
 * lockedStates[posted]; its routes bake with permission middleware OFF so a test can flip permission.enabled.)
 */

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('status')->default('draft');
        $t->string('secret')->nullable();
        $t->string('photo')->nullable();
        $t->integer('sort')->default(0);
        $t->timestamps();
    });
});

function importCsv(string $csv): \Illuminate\Testing\TestResponse
{
    $file = UploadedFile::fake()->createWithContent('rows.csv', $csv);

    return test()->post('/admin/action-widgets/import', ['file' => $file]);
}

it('strips a field the importing user lacks permission to write', function () {
    config()->set('admin-core.permission.enabled', true);
    test()->actingAs(new NotifiableUser(['name' => 'U'])); // no edit-secret-action-widget gate → denied

    importCsv("name,secret\nRow A,top-secret\n")->assertRedirect();

    expect(Widget::where('name', 'Row A')->first()->secret)->toBeNull(); // stripped, not imported
});

it('keeps a field the importing user IS permitted to write', function () {
    config()->set('admin-core.permission.enabled', true);
    Gate::define('edit-secret-action-widget', fn () => true);
    test()->actingAs(new NotifiableUser(['name' => 'U']));

    importCsv("name,secret\nRow B,keep\n")->assertRedirect();

    expect(Widget::where('name', 'Row B')->first()->secret)->not->toBeNull(); // permitted → imported (hashed)
});

it('refuses to import a row directly into a transition-owned state', function () {
    importCsv("name,status\nRow C,posted\n")->assertRedirect(); // transitions() declared → status is stripped

    expect(Widget::where('name', 'Row C')->first()->status)->toBe('draft'); // DB default, not the injected "posted"
});
