<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A related model whose human label lives in `title`, not `name` — the LMS catalog shape (Course, Lesson,
 * Document). Used to prove FK <select> labels + remote search resolve the display column (FI-6), not a
 * hardcoded `name`.
 */
class TitledCategory extends Model
{
    protected $table = 'titled_categories';

    protected $guarded = [];

    public $timestamps = false;
}
