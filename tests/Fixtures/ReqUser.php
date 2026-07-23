<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Named approval-requester fixture (table: req_users), replacing an in-test anonymous model so its morph
 * class name round-trips through a PostgreSQL text column. See {@see CauserUser} for the rationale. (TS-1)
 */
class ReqUser extends Model
{
    use SoftDeletes;

    protected $table = 'req_users';

    protected $guarded = [];

    public $timestamps = false;
}
