<?php

namespace Ngos\AdminCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The counter behind a `sequence` field — one row per (key, period) holding the last value handed out, so
 * concurrent creates serialise and get distinct numbers (allocated within the create's transaction, so a
 * rolled-back create releases its number). Managed by {@see \Ngos\AdminCore\Support\Sequence}.
 *
 * @property string $key
 * @property string $period
 * @property int $value
 */
class NumberSequence extends Model
{
    protected $table = 'number_sequences';

    protected $fillable = ['key', 'period', 'value'];

    public $timestamps = false;

    protected $casts = ['value' => 'integer'];
}
