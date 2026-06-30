<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Casts\MoneyCast;
use Ngos\AdminCore\Support\Money;

/* Per-record currency: one money column holding amounts in different currencies row-by-row (multi-currency),
   reading each row's code from a sibling `currency` column — a USD purchase next to a KHR one. */

beforeEach(function () {
    config()->set('admin-core.money.currency', 'USD');
    config()->set('admin-core.money.currencies', [
        'USD' => ['symbol' => '$', 'decimals' => 2, 'position' => 'before', 'thousands' => ',', 'decimal' => '.'],
        'KHR' => ['symbol' => '៛', 'decimals' => 0, 'position' => 'before', 'thousands' => ',', 'decimal' => '.'],
    ]);

    Schema::dropIfExists('pr_purchases');
    Schema::create('pr_purchases', function (Blueprint $t) {
        $t->id();
        $t->string('currency')->nullable();
        $t->bigInteger('total')->nullable();
        $t->timestamps();
    });
});

/** Exactly the cast the generator emits for `currency:enum:USD|KHR, total:money:@currency`. */
class PerRecordPurchase extends Model
{
    protected $table = 'pr_purchases';

    protected $fillable = ['currency', 'total'];

    protected function casts(): array
    {
        return ['total' => MoneyCast::class.':@currency'];
    }
}

it('stores + reads each row in its own currency (a USD row next to a KHR row in one column)', function () {
    $usd = PerRecordPurchase::create(['currency' => 'USD', 'total' => '15.00']);
    $khr = PerRecordPurchase::create(['currency' => 'KHR', 'total' => '15000']);

    // The stored minor units differ by the row currency's decimals (USD x100, KHR 1:1).
    expect(DB::table('pr_purchases')->where('id', $usd->id)->value('total'))->toBe(1500)
        ->and(DB::table('pr_purchases')->where('id', $khr->id)->value('total'))->toBe(15000);

    // Each reads back formatted in its OWN currency — resolved per row from the currency column.
    expect((string) $usd->fresh()->total)->toBe('$15.00')
        ->and($usd->fresh()->total->currency)->toBe('USD')
        ->and((string) $khr->fresh()->total)->toBe('៛15,000')
        ->and($khr->fresh()->total->currency)->toBe('KHR');
});

it('resolves the per-record currency on a row loaded fresh from the database (no in-memory state)', function () {
    $id = PerRecordPurchase::create(['currency' => 'KHR', 'total' => '7500'])->id;

    expect((string) PerRecordPurchase::find($id)->total)->toBe('៛7,500');
});

it('refuses a Money of a different currency than the row resolves to', function () {
    PerRecordPurchase::create(['currency' => 'KHR', 'total' => Money::fromMinor(1500, 'USD')]);
})->throws(InvalidArgumentException::class);

it('accepts a Money matching the row currency and stores its exact minor units', function () {
    $p = PerRecordPurchase::create(['currency' => 'USD', 'total' => Money::fromMinor(1999, 'USD')]);

    expect(DB::table('pr_purchases')->where('id', $p->id)->value('total'))->toBe(1999)
        ->and((string) $p->fresh()->total)->toBe('$19.99');
});

it('updates the amount keeping the row currency (already loaded on the model)', function () {
    $p = PerRecordPurchase::create(['currency' => 'KHR', 'total' => '5000']);
    $p->update(['total' => '8000']);

    expect(DB::table('pr_purchases')->where('id', $p->id)->value('total'))->toBe(8000)
        ->and((string) $p->fresh()->total)->toBe('៛8,000');
});

it('parses correctly when the currency is set before the amount (the natural / generated order)', function () {
    $p = new PerRecordPurchase();
    $p->currency = 'KHR'; // set FIRST — what the generated form/rules do
    $p->total = '15000';
    $p->save();

    expect(DB::table('pr_purchases')->where('id', $p->id)->value('total'))->toBe(15000); // KHR 1:1
});

it('falls back to the default currency when the amount is set before the currency (why order matters)', function () {
    // Footgun the make command warns about: total set before currency -> currency unknown -> default USD
    // decimals (2), so "15000" parses as $15000.00 = 1500000 minor, not KHR 15000. Hence currency-first.
    $p = new PerRecordPurchase();
    $p->total = '15000'; // currency not set yet -> default USD
    $p->currency = 'KHR';
    $p->save();

    expect(DB::table('pr_purchases')->where('id', $p->id)->value('total'))->toBe(1500000);
});

it('keeps null as null on a per-record money column', function () {
    $p = PerRecordPurchase::create(['currency' => 'USD', 'total' => null]);

    expect($p->fresh()->total)->toBeNull();
});

it('resolves the currency when the currency column is itself ENUM-cast (the real generated case)', function () {
    // The generator casts `currency:enum:USD|KHR` to a backed enum. The MoneyCast must read the RAW backing
    // code from $attributes ("USD"), not the materialised enum object — else it would fall back to the default.
    $usd = PerRecordEnumPurchase::create(['currency' => PrCurrency::USD, 'total' => '15.00']);
    $khr = PerRecordEnumPurchase::create(['currency' => PrCurrency::KHR, 'total' => '15000']);

    expect(DB::table('pr_purchases')->where('id', $usd->id)->value('total'))->toBe(1500)
        ->and(DB::table('pr_purchases')->where('id', $khr->id)->value('total'))->toBe(15000)
        ->and((string) $usd->fresh()->total)->toBe('$15.00')
        ->and((string) $khr->fresh()->total)->toBe('៛15,000');
});

it('imports correctly even when the CSV column order puts the amount before the currency', function () {
    // The import validates then creates with $validator->validated(), which Laravel keys in RULES order — and
    // the generated rules are in field-declaration order (currency first). So a CSV whose header is
    // "total,currency" (amount first) still fills currency first → the KHR amount is parsed 1:1, not x100.
    $row = ['total' => '15000', 'currency' => 'KHR']; // CSV header order: amount first
    $rules = ['currency' => ['required'], 'total' => ['required', 'numeric']]; // rules: currency first

    $validated = \Illuminate\Support\Facades\Validator::make($row, $rules)->validated();
    expect(array_key_first($validated))->toBe('currency'); // validated() reordered to rules order

    $p = PerRecordPurchase::create($validated);
    expect(DB::table('pr_purchases')->where('id', $p->id)->value('total'))->toBe(15000); // KHR 1:1, not 1500000
});

enum PrCurrency: string
{
    case USD = 'USD';
    case KHR = 'KHR';
}

class PerRecordEnumPurchase extends Model
{
    protected $table = 'pr_purchases';

    protected $fillable = ['currency', 'total'];

    protected function casts(): array
    {
        return ['currency' => PrCurrency::class, 'total' => MoneyCast::class.':@currency'];
    }
}
