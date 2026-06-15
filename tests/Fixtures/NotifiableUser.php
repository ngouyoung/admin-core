<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** A minimal Notifiable user for the notifications tests (mirrors App\Models\User: `use Notifiable`). */
class NotifiableUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}
