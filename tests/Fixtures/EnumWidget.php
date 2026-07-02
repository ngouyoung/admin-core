<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A Widget whose `status` is a real, CLASS-LEVEL backed-enum cast — mirroring a generated `status:enum`
 * resource. Shares the `widgets` table. The class-level cast (not an instance mergeCasts) is what makes the
 * re-fetching state-machine sites (runTransition, isLockedState) read an enum, so a revert of their unwrap
 * would fatal here.
 */
class EnumWidget extends Model
{
    protected $table = 'widgets';

    protected $fillable = ['name', 'status', 'secret', 'photo'];

    protected function casts(): array
    {
        return ['status' => EnumWidgetStatus::class];
    }
}
