<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal permission model for the generator's createPermissions() path — stands
 * in for the host's App\Models\Permission so the test doesn't pull in Spatie's
 * full schema. Points at the same `permissions` table the test creates.
 */
class HybridPermission extends Model
{
    protected $table = 'permissions';

    protected $guarded = [];
}
