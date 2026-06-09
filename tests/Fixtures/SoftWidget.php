<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SoftWidget extends Model
{
    use SoftDeletes;

    protected $table = 'soft_widgets';

    protected $fillable = ['name'];
}
