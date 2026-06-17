<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class RelTag extends Model
{
    protected $table = 'rel_tags';

    public $timestamps = false;

    protected $guarded = [];
}
