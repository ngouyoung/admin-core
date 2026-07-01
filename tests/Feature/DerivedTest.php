<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Relation-driven derived columns (--derived): a saving() hook denormalises a column from a picked belongsTo
   relation — qty_base = qty * unit.conversion_factor (compute), variant_id = unit.variant_id (copy). This test
   exercises exactly the hook the generator emits. */

beforeEach(function () {
    Schema::dropIfExists('dv_units');
    Schema::create('dv_units', function (Blueprint $t) {
        $t->id();
        $t->decimal('conversion_factor', 12, 4);
        $t->unsignedBigInteger('variant_id');
    });

    Schema::dropIfExists('dv_items');
    Schema::create('dv_items', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('unit_id')->nullable();
        $t->decimal('qty', 12, 3)->default(0);
        $t->decimal('qty_base', 12, 3)->default(0);
        $t->unsignedBigInteger('variant_id')->nullable();
    });
});

it('computes a derived column from a relation attribute and copies another on create', function () {
    $unit = DvUnit::create(['conversion_factor' => 24, 'variant_id' => 7]); // 1 carton = 24 base

    $item = DvItem::create(['unit_id' => $unit->id, 'qty' => 3]);

    expect((float) $item->fresh()->qty_base)->toBe(72.0)   // 3 * 24
        ->and((int) $item->fresh()->variant_id)->toBe(7);  // copied from the unit
});

it('recomputes on update when a source column changes', function () {
    $unit = DvUnit::create(['conversion_factor' => 24, 'variant_id' => 7]);
    $item = DvItem::create(['unit_id' => $unit->id, 'qty' => 3]);

    $item->update(['qty' => 5]);

    expect((float) $item->fresh()->qty_base)->toBe(120.0); // 5 * 24, recomputed
});

it('recomputes both derived columns when the related row changes', function () {
    $carton = DvUnit::create(['conversion_factor' => 24, 'variant_id' => 7]);
    $pack = DvUnit::create(['conversion_factor' => 6, 'variant_id' => 9]);
    $item = DvItem::create(['unit_id' => $carton->id, 'qty' => 2]);

    $item->update(['unit_id' => $pack->id]); // switch carton → pack

    expect((float) $item->fresh()->qty_base)->toBe(12.0)   // 2 * 6
        ->and((int) $item->fresh()->variant_id)->toBe(9);  // now the pack's variant
});

it('is null-safe when the relation is absent (no crash)', function () {
    $item = DvItem::create(['unit_id' => null, 'qty' => 5]);

    expect((float) $item->fresh()->qty_base)->toBe(0.0)     // qty * (null → 0)
        ->and($item->fresh()->variant_id)->toBeNull();      // copied null
});

class DvUnit extends Model
{
    protected $table = 'dv_units';

    protected $guarded = [];

    public $timestamps = false;
}

class DvItem extends Model
{
    protected $table = 'dv_items';

    protected $guarded = [];

    public $timestamps = false;

    // Exactly what the generator emits for
    // --derived="qty_base=qty*unit_id.conversion_factor, variant_id=unit_id.variant_id".
    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $unit = $model->unit_id ? DvUnit::find($model->unit_id) : null;
            $model->qty_base = ((float) ($model->qty ?? 0) * (float) ($unit?->conversion_factor ?? 0));
            $model->variant_id = $unit?->variant_id;
        });
    }
}
