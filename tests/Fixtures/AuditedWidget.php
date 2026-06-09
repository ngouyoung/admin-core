<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Ngos\AdminCore\Concerns\LogsActivity;

class AuditedWidget extends Model
{
    use LogsActivity;

    protected $table = 'widgets';

    protected $fillable = ['name'];
}
