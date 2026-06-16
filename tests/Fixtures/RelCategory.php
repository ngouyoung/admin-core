<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class RelCategory extends Model
{
    protected $table = 'rel_categories';

    public $timestamps = false;

    protected $guarded = [];
}
