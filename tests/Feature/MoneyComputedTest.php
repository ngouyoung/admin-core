<?php

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Casts\MoneyCast;
use Ngos\AdminCore\Support\Money;

/* The money-aware computed accessor, end-to-end: exactly the code the generator emits for
   `line_total:computed:qty * unit_price` where unit_price is money — runs at runtime and yields a Money. */

beforeEach(function () {
    config()->set('admin-core.money.currency', 'USD');
    config()->set('admin-core.money.currencies', [
        'USD' => ['symbol' => '$', 'decimals' => 2, 'position' => 'before', 'thousands' => ',', 'decimal' => '.'],
    ]);

    Schema::dropIfExists('comp_lines');
    Schema::create('comp_lines', function (Blueprint $t) {
        $t->id();
        $t->decimal('qty', 8, 3)->nullable();
        $t->bigInteger('unit_price')->nullable(); // money (minor units)
        $t->bigInteger('tax')->nullable();        // money
        $t->timestamps();
    });
});

/** Mirrors a generated model: money columns + money-typed computed accessors + $appends. */
class CompLine extends Model
{
    protected $table = 'comp_lines';

    protected $fillable = ['qty', 'unit_price', 'tax'];

    protected $appends = ['line_total', 'grand_total'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_price' => MoneyCast::class, 'tax' => MoneyCast::class];
    }

    // The generator's output for `line_total:computed:qty * unit_price` (money) — scalar × money via ?->multiply.
    protected function lineTotal(): Attribute
    {
        return Attribute::get(fn () => $this->unit_price?->multiply($this->qty));
    }

    // `grand_total:computed:unit_price + tax` — money + money via ?->add (the argument can be null).
    protected function grandTotal(): Attribute
    {
        return Attribute::get(fn () => $this->unit_price?->add($this->tax));
    }
}

it('computes a money line total (qty x money price) as an exact Money at runtime', function () {
    $line = CompLine::create(['qty' => '2.500', 'unit_price' => '3.00']); // $3.00 stored as 300 minor

    $total = $line->fresh()->line_total;

    expect($total)->toBeInstanceOf(Money::class)
        ->and($total->minor)->toBe(750)             // 2.5 × $3.00 = $7.50, exact
        ->and((string) $total)->toBe('$7.50');
});

it('serialises the money line total via $appends without error', function () {
    $line = CompLine::create(['qty' => '2', 'unit_price' => '1.99'])->fresh();

    expect($line->toArray()['line_total'])->toBe([
        'minor' => 398, 'major' => '3.98', 'currency' => 'USD', 'formatted' => '$3.98',
    ]);
});

it('stays null-safe when the money RECEIVER is null (no "method on null")', function () {
    $line = CompLine::create(['qty' => '2', 'unit_price' => null])->fresh();

    // ?->multiply short-circuits — the computed total is null, and toArray doesn't throw.
    expect($line->line_total)->toBeNull()
        ->and($line->toArray()['line_total'])->toBeNull();
});

it('stays null-safe when an ARGUMENT operand is null (the real nullable-column case)', function () {
    // unit_price present, qty NULL (a null factor) and tax NULL (a null addend) — must NOT TypeError.
    $line = CompLine::create(['qty' => null, 'unit_price' => '5.00', 'tax' => null])->fresh();

    expect($line->line_total)->toBeNull()        // 5.00 × null → null (not a crash)
        ->and($line->grand_total)->toBeNull()    // 5.00 + null tax → null
        ->and($line->toArray()['line_total'])->toBeNull()  // serialises (getData/show/API) without throwing
        ->and($line->toArray()['grand_total'])->toBeNull();
});

it('adds two money columns when both are present', function () {
    $line = CompLine::create(['qty' => '1', 'unit_price' => '5.00', 'tax' => '0.50'])->fresh();

    expect((string) $line->grand_total)->toBe('$5.50');
});
