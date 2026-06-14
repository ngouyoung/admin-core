<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    protected $fillable = ['name', 'status', 'secret'];

    // A hashed column with NO $hidden — so the CSV export's safety net (drop hashed-cast
    // columns) is what keeps the hash out of the file.
    protected function casts(): array
    {
        return ['secret' => 'hashed'];
    }
}
