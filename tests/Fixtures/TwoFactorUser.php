<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Ngos\AdminCore\Concerns\TwoFactorAuthenticatable;

/** Minimal user that uses the 2FA trait (mirrors App\Models\User after --access installs it). */
class TwoFactorUser extends Authenticatable
{
    use TwoFactorAuthenticatable;

    protected $table = 'users';

    protected $guarded = [];
}
