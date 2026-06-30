<?php

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Casts\MoneyCast;
use Ngos\AdminCore\Support\Money;
use Ngos\AdminCore\Support\Rollup;

/* rollup: a document total = sum of a child relation, money-aware. The whole master-detail story end-to-end:
   an order with money line items (qty × unit_price per line) and a document total (sum of the lines). */

beforeEach(function () {
    config()->set('admin-core.money.currency', 'USD');
    config()->set('admin-core.money.currencies', [
        'USD' => ['symbol' => '$', 'decimals' => 2, 'position' => 'before', 'thousands' => ',', 'decimal' => '.'],
    ]);

    Schema::dropIfExists('rollup_lines');
    Schema::dropIfExists('rollup_orders');
    Schema::create('rollup_orders', function (Blueprint $t) {
        $t->id();
        $t->timestamps();
    });
    Schema::create('rollup_lines', function (Blueprint $t) {
        $t->id();
        $t->foreignId('rollup_order_id');
        $t->decimal('qty', 8, 3);
        $t->bigInteger('unit_price')->nullable(); // money
        $t->timestamps();
    });
});

// -- the Rollup::sum helper --------------------------------------------------------------------------

it('sums money values exactly via Money, skipping nulls', function () {
    $items = collect([
        (object) ['amount' => Money::fromMinor(150, 'USD')],   // $1.50
        (object) ['amount' => Money::fromMinor(75, 'USD')],    // $0.75
        (object) ['amount' => null],                            // skipped
    ]);

    $sum = Rollup::sum($items, 'amount');
    expect($sum)->toBeInstanceOf(Money::class)
        ->and($sum->minor)->toBe(225)
        ->and((string) $sum)->toBe('$2.25');
});

it('sums plain numbers numerically and an empty set to 0', function () {
    $items = collect([(object) ['n' => '2.5'], (object) ['n' => 3], (object) ['n' => null]]);

    expect(Rollup::sum($items, 'n'))->toBe(5.5)
        ->and(Rollup::sum(collect([]), 'n'))->toBe(0);
});

it('fails loudly on a mix of money and plain numbers (never silently drops rows)', function () {
    $items = collect([
        (object) ['x' => Money::fromMinor(500, 'USD')],
        (object) ['x' => 3], // a plain number among money — inconsistent child type
    ]);

    expect(fn () => Rollup::sum($items, 'x'))
        ->toThrow(InvalidArgumentException::class, 'mixes Money and plain numbers');
});

it('fails with rollup context when child lines have different currencies', function () {
    $items = collect([
        (object) ['x' => Money::fromMinor(500, 'USD')],
        (object) ['x' => Money::fromMinor(15000, 'KHR')],
    ]);

    expect(fn () => Rollup::sum($items, 'x'))
        ->toThrow(InvalidArgumentException::class, 'must share one currency');
});

// -- end-to-end master-detail document ---------------------------------------------------------------

it('rolls up money line totals into an exact document total', function () {
    $order = RollupOrder::create();
    // line totals are themselves money-aware computed: qty × unit_price.
    $order->lines()->create(['qty' => '2', 'unit_price' => '1.50']);   // 2 × $1.50 = $3.00
    $order->lines()->create(['qty' => '1.5', 'unit_price' => '2.00']); // 1.5 × $2.00 = $3.00

    $total = $order->fresh()->total;

    expect($total)->toBeInstanceOf(Money::class)
        ->and($total->minor)->toBe(600)              // $3.00 + $3.00 = $6.00
        ->and((string) $total)->toBe('$6.00');
});

it('serialises the rolled-up total + survives an order with no lines (total 0)', function () {
    $order = RollupOrder::create();
    $order->lines()->create(['qty' => '2', 'unit_price' => '2.50']); // $5.00

    expect((string) $order->fresh()->total)->toBe('$5.00')
        ->and($order->fresh()->toArray()['total'])->toBe([
            'minor' => 500, 'major' => '5.00', 'currency' => 'USD', 'formatted' => '$5.00',
        ]);

    expect(RollupOrder::create()->fresh()->total)->toBe(0); // no lines → 0, no crash
});

class RollupOrder extends Model
{
    protected $table = 'rollup_orders';

    protected $guarded = [];

    protected $appends = ['total'];

    public function lines()
    {
        return $this->hasMany(RollupLine::class);
    }

    // The generator's output for `total:rollup:lines.line_total`.
    protected function total(): Attribute
    {
        return Attribute::get(fn () => Rollup::sum($this->lines, 'line_total'));
    }
}

class RollupLine extends Model
{
    protected $table = 'rollup_lines';

    protected $guarded = [];

    protected $appends = ['line_total'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_price' => MoneyCast::class];
    }

    // `line_total:computed:qty * unit_price` (money).
    protected function lineTotal(): Attribute
    {
        return Attribute::get(fn () => $this->unit_price?->multiply($this->qty));
    }
}
