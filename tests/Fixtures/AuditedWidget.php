<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Ngos\AdminCore\Concerns\LogsActivity;

class AuditedWidget extends Model
{
    use LogsActivity;

    protected $table = 'widgets';

    protected $fillable = ['name', 'secret'];

    // A password column hidden by its real name (not literally "password") — its hash
    // must never reach the activity log.
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'hashed'];
    }
}
