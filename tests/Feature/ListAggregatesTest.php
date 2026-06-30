<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\Widget;

/**
 * WebController::listAggregates → the list-footer totals, computed server-side over the FILTERED set (all
 * pages) and returned in the getData response as `acAggregates`. A money column sums to a formatted Money; a
 * plain column to a number; the total reflects the active list filters. (WidgetController declares a money
 * `price` sum, a plain `sort` sum, and a bogus spec that must be ignored, never run as SQL.)
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
        $table->bigInteger('price')->nullable();
        $table->timestamps();
    });
});

/** The acAggregates block from the getData DataTables endpoint. */
function widgetAggregates(string $query = ''): array
{
    return test()->getJson('/admin/widgets/getData' . $query)->assertOk()->json('acAggregates') ?? [];
}

/** price + sort aren't in Widget's $fillable (so create() drops them) — set them with forceFill. */
function makeWidget(string $name, ?string $status, int $price, int $sort = 0): void
{
    Widget::create(['name' => $name, 'status' => $status])->forceFill(['price' => $price, 'sort' => $sort])->save();
}

it('totals a money column (formatted) and a plain column (numeric) across all rows', function () {
    makeWidget('A', 'active', 15000, 2);
    makeWidget('B', 'archived', 10000, 3);

    $agg = widgetAggregates();

    expect($agg['price'])->toBe('៛25,000')   // money sum, formatted in the column's currency
        ->and($agg['sort'])->toBe(5);        // plain numeric sum
});

it('reflects the active list filter (totals the filtered set, not every row)', function () {
    makeWidget('A', 'active', 15000);
    makeWidget('B', 'archived', 10000);
    makeWidget('C', 'archived', 30000);

    // Filtering to archived → the footer totals only B + C.
    expect(widgetAggregates('?filter[status]=archived')['price'])->toBe('៛40,000');
});

it('totals an empty/zero result as the currency zero, not null', function () {
    // No rows match → SUM is 0 → formatted as the currency's zero.
    expect(widgetAggregates('?filter[status]=nope')['price'])->toBe('៛0');
});

it('ignores a non-whitelisted aggregate function (never runs it as SQL)', function () {
    makeWidget('A', 'active', 1000);

    // 'name' => 'drop table' is not a real aggregate fn — it must be silently skipped, not error or inject.
    expect(widgetAggregates())->not->toHaveKey('name');
});

it('preserves a plain numeric total exactly — huge integers stay strings (no int64 → float overflow)', function () {
    // MySQL returns SUM/AVG as a STRING; a value past PHP_INT_MAX must not coerce to a lossy float ("1.8e19").
    $controller = app(\Ngos\AdminCore\Tests\Fixtures\WidgetController::class);
    $numericAggregate = new ReflectionMethod($controller, 'numericAggregate');

    expect($numericAggregate->invoke($controller, 5))->toBe(5)                  // native int (SQLite)
        ->and($numericAggregate->invoke($controller, '5'))->toBe(5)            // integer string (MySQL) → exact int
        ->and($numericAggregate->invoke($controller, '-12'))->toBe(-12)
        ->and($numericAggregate->invoke($controller, '5.5'))->toBe(5.5)        // decimal string → float
        ->and($numericAggregate->invoke($controller, '18000000000000000000')) // too big for int64 → kept exact
        ->toBe('18000000000000000000');
});
