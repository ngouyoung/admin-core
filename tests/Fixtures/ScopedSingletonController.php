<?php

namespace Ngos\AdminCore\Tests\Fixtures;

/** A per-owner singleton — recordScope() pins (and re-asserts) the row, so a posted scope column can't hijack it. */
class ScopedSingletonController extends SettingSingletonController
{
    protected function recordScope(): array
    {
        return ['status' => 'locked']; // a constant stand-in for e.g. ['user_id' => auth()->id()]
    }
}
