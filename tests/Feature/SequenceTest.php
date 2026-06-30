<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\NumberSequence;
use Ngos\AdminCore\Support\Sequence;

/* Sequential document numbers: the concurrency-safe Sequence counter + the generated creating-hook that
   assigns the next number on a `sequence` field. */

beforeEach(function () {
    Schema::dropIfExists('number_sequences');
    Schema::create('number_sequences', function (Blueprint $t) {
        $t->id();
        $t->string('key');
        $t->string('period')->default('');
        $t->unsignedBigInteger('value')->default(0);
        $t->unique(['key', 'period']);
    });

    Schema::dropIfExists('seq_docs');
    Schema::create('seq_docs', function (Blueprint $t) {
        $t->id();
        $t->string('code')->nullable()->unique();
        $t->timestamps();
    });
});

afterEach(fn () => Carbon::setTestNow());

// -- the Sequence helper -----------------------------------------------------------------------------

it('hands out consecutive, prefixed, zero-padded numbers', function () {
    expect(Sequence::next('inv', 'INV-'))->toBe('INV-0001')
        ->and(Sequence::next('inv', 'INV-'))->toBe('INV-0002')
        ->and(Sequence::next('inv', 'INV-'))->toBe('INV-0003');

    // A different key counts independently; padding is configurable; a bare key has no prefix.
    expect(Sequence::next('grn', 'GRN-', 6))->toBe('GRN-000001')
        ->and(Sequence::next('plain'))->toBe('0001');
});

it('persists the counter as one row per key', function () {
    Sequence::next('a');
    Sequence::next('a');
    Sequence::next('b');

    expect(NumberSequence::where('key', 'a')->where('period', '')->value('value'))->toBe(2)
        ->and(NumberSequence::where('key', 'b')->value('value'))->toBe(1)
        ->and(NumberSequence::count())->toBe(2);
});

it('resets the counter per period (year) and stamps the period into the number', function () {
    Carbon::setTestNow('2026-12-31 23:59:59');
    expect(Sequence::next('inv', 'INV-', 4, 'year'))->toBe('INV-2026-0001')
        ->and(Sequence::next('inv', 'INV-', 4, 'year'))->toBe('INV-2026-0002');

    Carbon::setTestNow('2027-01-01 00:00:00'); // new year → counter restarts at 1
    expect(Sequence::next('inv', 'INV-', 4, 'year'))->toBe('INV-2027-0001');
});

// -- the generated creating hook ---------------------------------------------------------------------

it('assigns the next number to a new record (the generated hook), keeping a pre-set one', function () {
    $a = SeqDoc::create([]);
    $b = SeqDoc::create([]);

    expect($a->code)->toBe('INV-0001')
        ->and($b->code)->toBe('INV-0002');

    // A manually-set number is preserved (the hook uses ??=), and the counter isn't consumed for it.
    $c = SeqDoc::create(['code' => 'MANUAL-1']);
    expect($c->code)->toBe('MANUAL-1')
        ->and(SeqDoc::create([])->code)->toBe('INV-0003');
});

it('releases a number when its create rolls back — the next create reuses it (no gap, no burned number)', function () {
    // The number is allocated inside the create's transaction, so rolling that back releases the counter.
    try {
        DB::transaction(function () {
            SeqDoc::create([]);                  // allocates INV-0001
            throw new \RuntimeException('boom'); // roll the whole create back
        });
    } catch (\RuntimeException) {
        // expected
    }

    expect(SeqDoc::count())->toBe(0);                  // nothing committed
    expect(SeqDoc::create([])->code)->toBe('INV-0001'); // the next committed create reuses 0001 — no gap
});

class SeqDoc extends Model
{
    protected $table = 'seq_docs';

    protected $guarded = [];

    protected static function booted(): void
    {
        // Exactly what the generator emits for `code:sequence:INV`.
        static::creating(function (self $model) {
            $model->code ??= Sequence::next('seq_docs.code', 'INV-');
        });
    }
}
