<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Ngos\AdminCore\Concerns\HasPublicUuid;

/**
 * A widget on the hybrid key strategy (bigint `id` + public `uuid` route key) —
 * the generator's default. Lets the suite exercise resolve-by-uuid end to end.
 */
class HybridWidget extends Model
{
    use HasPublicUuid;

    protected $table = 'hybrid_widgets';

    protected $fillable = ['name'];
}
