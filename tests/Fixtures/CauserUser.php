<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Named morph causer/subject fixture (table: causer_users), replacing an in-test anonymous model.
 *
 * An anonymous class name carries a NUL byte (e.g. "…@anonymous\0/path:line$0") that PostgreSQL text
 * columns reject/truncate — so an anonymous model stored as a morphTo type cannot round-trip on pgsql
 * (morph resolution then instantiates a truncated, non-existent class). A named class has no NUL byte
 * and round-trips on every engine. Real apps never store anonymous class names as morph types. (TS-1)
 */
class CauserUser extends Model
{
    use SoftDeletes;

    protected $table = 'causer_users';

    protected $guarded = [];

    public $timestamps = false;
}
