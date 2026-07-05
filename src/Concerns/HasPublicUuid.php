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
        // `saving` (not just `creating`) so a legacy row that predates the uuid column — retrofitted onto an
        // existing users table by `admin-core:install --access` — is healed on its next save too, not only
        // fresh inserts. The empty() guard makes it a no-op once the row has a uuid. (The install migration
        // also backfills existing rows up front; this is the belt-and-suspenders for any that slip through.)
        static::saving(function ($model): void {
            $key = $model->getPublicKeyName();
            if (empty($model->{$key})) {
                // UUID v7: time-ordered (index-friendly) + RFC 9562 standard for interop.
                $model->{$key} = (string) (method_exists(Str::class, 'uuid7') ? Str::uuid7() : Str::orderedUuid());
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
