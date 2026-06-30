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
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('status')->nullable();
        $table->string('secret')->nullable();
        $table->string('photo')->nullable();
        $table->integer('sort')->default(0);
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
