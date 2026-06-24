<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RelCategory extends Model
{
    protected $table = 'rel_categories';

    public $timestamps = false;

    protected $guarded = [];

    public function gadgets(): HasMany
    {
        return $this->hasMany(RelGadget::class, 'category_id');
    }
}
