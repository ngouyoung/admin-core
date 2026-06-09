<?php

namespace Ngos\AdminCore\Concerns;

use Illuminate\Support\Str;

/**
 * Hybrid key strategy.
 *
 * The model keeps its fast auto-increment `id` for the primary key, foreign keys
 * and joins (lean indexes that never bloat), while a unique, time-ordered `uuid`
 * column is the *public* identifier — used in URLs (route-model binding) and APIs.
 * So ids are never enumerable, but the database stays bigint-fast at any scale.
 *
 * Requires a `uuid` column: `$table->uuid('uuid')->unique();`
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function ($model): void {
            $key = $model->getPublicKeyName();
            if (empty($model->{$key})) {
                $model->{$key} = (string) Str::orderedUuid();
            }
        });
    }

    /** The column holding the public (URL/API) identifier. */
    public function getPublicKeyName(): string
    {
        return 'uuid';
    }

    /** Route-model binding + route() URLs use the uuid, never the bigint id. */
    public function getRouteKeyName(): string
    {
        return $this->getPublicKeyName();
    }
}
