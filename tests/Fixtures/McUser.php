<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/**
 * Named maker-checker user fixture (table: mc_users), replacing an in-test anonymous model so its morph
 * class name round-trips through a PostgreSQL text column. See {@see CauserUser} for the rationale. (TS-1)
 */
class McUser extends User
{
    protected $table = 'mc_users';

    protected $guarded = [];

    public $timestamps = false;
}
