<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/**
 * Named portal-guard user fixture (table: merchant_users), replacing an in-test anonymous model so its
 * morph class name round-trips through a PostgreSQL text column. See {@see CauserUser} for the rationale. (TS-1)
 */
class MerchantUser extends User
{
    protected $table = 'merchant_users';

    protected $guarded = [];

    public $timestamps = false;
}
