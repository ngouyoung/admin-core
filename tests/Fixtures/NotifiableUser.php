<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** A minimal Notifiable user for the notifications tests (Foundation\Auth\User uses Notifiable). */
class NotifiableUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
