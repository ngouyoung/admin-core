<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\Widget;

/**
 * WebController::applyListFilters over the WidgetController getData endpoint (listFilters declares a `status`
 * select + a `created_at` date range). A whitelist blocks any non-declared column; yajra's own search/sort
 * still run afterward.
 */
beforeEach(function () {
    config()->set('admin-core.money.currency', 'USD');
    config()->set('admin-core.money.currencies', [
        'KHR' => ['symbol' => '៛', 'decimals' => 0, 'position' => 'before', 'thousands' => ',', 'decimal' => '.'],
    ]);
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('status')->nullable();
        $table->string('secret')->nullable();
        $table->string('photo')->nullable();
        $table->integer('sort')->default(0);
        $table->bigInteger('price')->nullable(); // money, minor units
        $table->timestamps();
    });
});

/** Rows returned by the getData DataTables endpoint with the given query string. */
function rows(string $query = ''): array
{
    return test()->getJson('/admin/widgets/getData' . $query)->assertOk()->json('data');
}

it('returns everything with no filter', function () {
    Widget::create(['name' => 'A', 'status' => 'active']);
    Widget::create(['name' => 'B', 'status' => 'archived']);

    expect(rows())->toHaveCount(2);
});

it('filters by a declared select column (exact match)', function () {
    Widget::create(['name' => 'A', 'status' => 'active']);
    Widget::create(['name' => 'B', 'status' => 'archived']);
    Widget::create(['name' => 'C', 'status' => 'archived']);

    $data = rows('?filter[status]=archived');

    expect($data)->toHaveCount(2)
        ->and(collect($data)->pluck('name')->sort()->values()->all())->toBe(['B', 'C']);
});

it('treats an empty filter value as no filter', function () {
    Widget::create(['name' => 'A', 'status' => 'active']);
    Widget::create(['name' => 'B', 'status' => 'archived']);

    expect(rows('?filter[status]='))->toHaveCount(2); // blank select = "All"
});

it('filters by a declared date range (from / to, inclusive)', function () {
    Widget::create(['name' => 'Old'])->forceFill(['created_at' => '2026-01-10 09:00:00'])->save();
    Widget::create(['name' => 'Mid'])->forceFill(['created_at' => '2026-02-15 09:00:00'])->save();
    Widget::create(['name' => 'New'])->forceFill(['created_at' => '2026-03-20 09:00:00'])->save();

    expect(collect(rows('?filter[created_at][from]=2026-02-01&filter[created_at][to]=2026-02-28'))->pluck('name')->all())
        ->toBe(['Mid']);
    // Only a `from` bound → everything on/after it.
    expect(collect(rows('?filter[created_at][from]=2026-02-15'))->pluck('name')->sort()->values()->all())
        ->toBe(['Mid', 'New']);
});

it('ignores a non-date bound instead of string-comparing it (no silent match-all/none)', function () {
    Widget::create(['name' => 'A'])->forceFill(['created_at' => '2026-01-10 09:00:00'])->save();
    Widget::create(['name' => 'B'])->forceFill(['created_at' => '2026-02-10 09:00:00'])->save();

    // A hand-crafted non-date must be skipped (not bound into whereDate, where a string compare would match
    // all rows for `to` / none for `from` depending on the driver).
    expect(rows('?filter[created_at][from]=abc'))->toHaveCount(2)
        ->and(rows('?filter[created_at][to]=abc'))->toHaveCount(2)
        ->and(rows('?filter[created_at][from][]=2026-01-01'))->toHaveCount(2); // array bound dropped too
});

it('filters a text column with a LIKE match', function () {
    Widget::create(['name' => 'Apple pie']);
    Widget::create(['name' => 'Banana']);
    Widget::create(['name' => 'Pineapple']);

    expect(collect(rows('?filter[name]=apple'))->pluck('name')->sort()->values()->all())
        ->toBe(['Apple pie', 'Pineapple']); // LIKE %apple%, case-insensitive
});

it('filters a numeric column by a min/max range (inclusive)', function () {
    Widget::create(['name' => 'A'])->forceFill(['sort' => 5])->save();
    Widget::create(['name' => 'B'])->forceFill(['sort' => 15])->save();
    Widget::create(['name' => 'C'])->forceFill(['sort' => 25])->save();

    expect(collect(rows('?filter[sort][min]=10&filter[sort][max]=20'))->pluck('name')->all())->toBe(['B'])
        ->and(collect(rows('?filter[sort][min]=15'))->pluck('name')->sort()->values()->all())->toBe(['B', 'C']);
});

it('filters a money column by converting the major-amount bounds to stored minor units', function () {
    // price is money:KHR (0-decimal → minor == major). Stored minor: 500/1500/2500.
    Widget::create(['name' => 'Cheap'])->forceFill(['price' => 500])->save();
    Widget::create(['name' => 'Mid'])->forceFill(['price' => 1500])->save();
    Widget::create(['name' => 'Pricey'])->forceFill(['price' => 2500])->save();

    // A major range 1000–2000 → minor 1000–2000 → matches only Mid.
    expect(collect(rows('?filter[price][min]=1000&filter[price][max]=2000'))->pluck('name')->all())->toBe(['Mid']);
});

it('never evaluates a foreign filter options closure on a data request (perf — query only at render)', function () {
    Widget::create(['name' => 'A', 'status' => 'active']);

    // The fixture declares a category_id select whose options closure THROWS if evaluated. A getData hit
    // (here even with another filter applied) must succeed — proving applyListFilters never touches options.
    expect(rows('?filter[status]=active'))->toHaveCount(1);
    expect(rows())->toHaveCount(1);
});

it('ignores a non-numeric range bound and a non-scalar text value (no error)', function () {
    Widget::create(['name' => 'A', 'sort' => 5]);

    expect(rows('?filter[sort][min]=abc'))->toHaveCount(1)        // non-numeric min skipped
        ->and(rows('?filter[name][]=A'))->toHaveCount(1);         // array text value dropped
});

it('ignores a filter on a column not declared in listFilters (whitelist)', function () {
    Widget::create(['name' => 'A', 'secret' => 'x']);
    Widget::create(['name' => 'B', 'secret' => 'y']);

    // `secret` is a real column but NOT a declared filter → the param is ignored, not applied.
    expect(rows('?filter[secret]=x'))->toHaveCount(2);
});

it('ignores a non-scalar value for a select filter instead of erroring', function () {
    Widget::create(['name' => 'A', 'status' => 'active']);

    // ?filter[status][]=x would bind an array into where() — must be dropped, not 500.
    expect(rows('?filter[status][]=active'))->toHaveCount(1);
});
