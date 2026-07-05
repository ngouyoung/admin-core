<?php

namespace Ngos\AdminCore\Tests\Fixtures;

/**
 * A state singleton that deterministically OPENS the locked-state TOCTOU window: it flips the stored row to
 * a locked state inside stripStateColumn() — which update() calls AFTER its pre-transaction locked check but
 * BEFORE the row-locked write — standing in for a concurrent transition landing in that race. Only the
 * re-check on the row-locked instance inside the transaction can catch it.
 */
class RacySingletonController extends StateSingletonController
{
    protected function stripStateColumn(array $data): array
    {
        // Simulate a concurrent transition moving the record into a locked state mid-request.
        Widget::query()->update(['status' => 'locked']);

        return parent::stripStateColumn($data);
    }
}
