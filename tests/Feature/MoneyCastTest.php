<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Casts\MoneyCast;
use Ngos\AdminCore\Support\Money;

/* MoneyCast: a major amount in -> exact minor units stored -> a Money object back out. */

beforeEach(function () {
    config()->set('admin-core.money.currency', 'USD');
    config()->set('admin-core.money.currencies', [
        'USD' => ['symbol' => '$', 'decimals' => 2, 'position' => 'before', 'thousands' => ',', 'decimal' => '.'],
        'KHR' => ['symbol' => '៛', 'decimals' => 0, 'position' => 'before', 'thousands' => ',', 'decimal' => '.'],
        'EUR' => ['symbol' => '€', 'decimals' => 2, 'position' => 'after', 'thousands' => '.', 'decimal' => ','],
    ]);

    Schema::dropIfExists('money_samples');
    Schema::create('money_samples', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('price')->nullable();
        $t->bigInteger('cost')->nullable(); // pinned to KHR via the cast argument
        $t->timestamps();
    });
});

/** An inline model exercising both a default-currency and a pinned-currency money column. */
class MoneySample extends Model
{
    protected $table = 'money_samples';

    protected $fillable = ['price', 'cost'];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,           // default currency (USD)
            'cost' => MoneyCast::class.':KHR',      // pinned currency
        ];
    }
}

it('stores a major form value as minor units in the column', function () {
    $m = MoneySample::create(['price' => '15.00']);

    // The raw DB column is the integer minor units, not the decimal.
    expect(DB::table('money_samples')->where('id', $m->id)->value('price'))->toBe(1500);
});

it('reads the column back as a Money object', function () {
    $m = MoneySample::create(['price' => '15.50']);

    expect($m->fresh()->price)->toBeInstanceOf(Money::class)
        ->and($m->fresh()->price->minor)->toBe(1550)
        ->and((string) $m->fresh()->price)->toBe('$15.50');
});

it('uses the column-pinned currency for storage and display', function () {
    $m = MoneySample::create(['price' => '15.00', 'cost' => '15000']);

    expect(DB::table('money_samples')->where('id', $m->id)->value('cost'))->toBe(15000) // KHR: 1:1, no x100
        ->and((string) $m->fresh()->cost)->toBe('៛15,000')
        ->and($m->fresh()->cost->currency)->toBe('KHR');
});

it('accepts a Money object directly and stores its exact minor units', function () {
    $m = MoneySample::create(['price' => Money::fromMinor(1999, 'USD')]);

    expect(DB::table('money_samples')->where('id', $m->id)->value('price'))->toBe(1999)
        ->and((string) $m->fresh()->price)->toBe('$19.99');
});

it('round-trips an exact amount through save and reload with no drift', function () {
    $m = MoneySample::create(['price' => '0.10']);
    $m->price = $m->price->add(Money::fromMajor('0.20', 'USD'));
    $m->save();

    expect(DB::table('money_samples')->where('id', $m->id)->value('price'))->toBe(30)
        ->and((string) $m->fresh()->price)->toBe('$0.30'); // 0.1 + 0.2 stays 0.30, exactly
});

it('keeps null as null (a nullable money column)', function () {
    $m = MoneySample::create(['price' => null]);

    expect(DB::table('money_samples')->where('id', $m->id)->value('price'))->toBeNull()
        ->and($m->fresh()->price)->toBeNull();
});

it('treats an empty string as null, not zero', function () {
    $m = MoneySample::create(['price' => '']);

    expect($m->fresh()->price)->toBeNull();
});

it('exports a Money value as the plain major amount so it re-imports exactly', function () {
    $controller = app(\Ngos\AdminCore\Tests\Fixtures\WidgetController::class);
    $csvCell = new ReflectionMethod($controller, 'csvCell'); // PHP 8.1+: protected methods need no setAccessible

    // The CSV must carry "15.00" (re-parseable by the MoneyCast), never the formatted "$15.00".
    expect($csvCell->invoke($controller, Money::fromMinor(1500, 'USD')))->toBe('15.00')
        ->and($csvCell->invoke($controller, Money::fromMinor(15000, 'KHR')))->toBe('15000');
});

it('round-trips a comma-decimal currency through the cast on dot-decimal input (the real form path)', function () {
    config()->set('admin-core.money.currency', 'EUR'); // price column has no pinned currency -> uses default

    // The form / export always post dot-decimal ("1234.50"), so EUR stores + reads back exactly.
    $m = MoneySample::create(['price' => '1234.50']);

    expect(DB::table('money_samples')->where('id', $m->id)->value('price'))->toBe(123450)
        ->and((string) $m->fresh()->price)->toBe('1.234,50€'); // localized only on display
});

it('refuses a Money of the wrong currency on a pinned column', function () {
    // cost is pinned to KHR; a USD Money must not be silently reinterpreted as riel.
    MoneySample::create(['cost' => Money::fromMinor(1500, 'USD')]);
})->throws(InvalidArgumentException::class);

it('treats false as null (not zero)', function () {
    $m = MoneySample::create(['price' => false]);

    expect($m->fresh()->price)->toBeNull();
});


it('throws on an out-of-range amount instead of silently storing $0.00', function () {
    // (float) '1e400' is INF — every digit strips away in the parser, so without the guard the submitted
    // amount silently became 0 minor units. An unstorable amount must FAIL loudly.
    expect(fn () => \Ngos\AdminCore\Support\Money::fromMajor('1e400', 'USD'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => \Ngos\AdminCore\Support\Money::fromMajor(INF, 'USD'))
        ->toThrow(InvalidArgumentException::class);
    // 20 integer digits → minor units past PHP_INT_MAX: the (int) cast would wrap/zero it.
    expect(fn () => \Ngos\AdminCore\Support\Money::fromMajor('99999999999999999999', 'USD'))
        ->toThrow(InvalidArgumentException::class);

    // Ordinary amounts (incl. exact half-cent rounding and scientific shorthand) still parse.
    expect(\Ngos\AdminCore\Support\Money::fromMajor('15.00', 'USD')->minor)->toBe(1500)
        ->and(\Ngos\AdminCore\Support\Money::fromMajor('1.005', 'USD')->minor)->toBe(101)
        ->and(\Ngos\AdminCore\Support\Money::fromMajor('1e3', 'USD')->minor)->toBe(100000);
});


it('throws when multiply/divide overflows the minor-unit int instead of yielding $0.00', function () {
    // (int) of an overflowing float is undefined (observed: 0) — $15.00 x PHP_INT_MAX silently became $0.00.
    $m = \Ngos\AdminCore\Support\Money::fromMinor(1500, 'USD');

    expect(fn () => $m->multiply(PHP_INT_MAX))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $m->divide('0.0000000000000001'))->toThrow(InvalidArgumentException::class);

    // Ordinary arithmetic still exact.
    expect($m->multiply('2.5')->minor)->toBe(3750)
        ->and($m->divide(3)->minor)->toBe(500)
        ->and($m->multiply(-1)->minor)->toBe(-1500);
});

it('multiplies/divides by a scientific-notation-stringified float without crashing (bcmath rejects E-form)', function () {
    // (string) 0.00005 is '5.0E-5' — is_numeric() accepts it but bcmul()/bcdiv() throw ValueError, so an
    // ordinary small rate (a per-mille commission, a conversion rate under 0.0001) crashed every multiply.
    $m = \Ngos\AdminCore\Support\Money::fromMinor(100000, 'USD'); // $1,000.00

    expect($m->multiply(0.00005)->minor)->toBe(5)              // 100000 × 0.00005 = 5 minor units, exactly
        ->and($m->multiply(-0.00005)->minor)->toBe(-5)          // sign preserved through the E-form
        ->and($m->divide(0.0001)->minor)->toBe(1000000000)      // ÷ 1.0E-4 (E-form divisor)
        ->and(\Ngos\AdminCore\Support\Money::fromMinor(3, 'USD')->multiply(0.0000625)->minor)->toBe(0); // rounds, no crash

    // Large-exponent E-form still overflow-guarded, not wrapped.
    expect(fn () => $m->multiply(1.5e18))->toThrow(InvalidArgumentException::class);
});

it('multiplies/divides with EXACT decimal arithmetic (no binary-float off-by-one-minor-unit)', function () {
    // $280.60 x 32.425 (a decimal(8,3) qty) is exactly 909845.5 minor units → 909846 half-up. The old float
    // product rounded to 909845 — off by a cent on an ordinary line total.
    expect(\Ngos\AdminCore\Support\Money::fromMinor(28060, 'USD')->multiply('32.425')->minor)->toBe(909846);

    // A few more exact boundaries that binary float gets wrong.
    expect(\Ngos\AdminCore\Support\Money::fromMinor(1005, 'USD')->multiply('1.005')->minor)->toBe(1010) // 1010.025 → 1010
        ->and(\Ngos\AdminCore\Support\Money::fromMinor(2000, 'USD')->divide('3')->minor)->toBe(667)     // 666.67 → 667
        ->and(\Ngos\AdminCore\Support\Money::fromMinor(-28060, 'USD')->multiply('32.425')->minor)->toBe(-909846); // sign preserved
});
