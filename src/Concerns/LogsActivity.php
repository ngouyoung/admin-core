<?php

namespace Ngos\AdminCore\Concerns;

use Ngos\AdminCore\Models\ActivityLog;

/**
 * Add to any model to record created/updated/deleted entries in activity_logs,
 * capturing the authenticated causer and the changed attributes.
 *
 *   use Ngos\AdminCore\Concerns\LogsActivity;
 *   class Product extends Model { use LogsActivity; }
 *
 * Override $activityHidden to keep extra attributes out of the log.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(fn ($model) => $model->recordActivity('created'));
        static::updated(fn ($model) => $model->recordActivity('updated'));
        static::deleted(fn ($model) => $model->recordActivity('deleted'));
        // 'restored' only ever fires for SoftDeletes models; harmless (never dispatched) otherwise.
        // Without it, un-deleting a record left no audit trail.
        static::restored(fn ($model) => $model->recordActivity('restored'));
    }

    public function recordActivity(string $description): void
    {
        $properties = match ($description) {
            'created' => ['attributes' => $this->getAttributes()],
            'updated' => [
                'old' => array_intersect_key($this->getOriginal(), $this->getChanges()),
                'attributes' => $this->getChanges(),
            ],
            default => [],
        };

        // Never let audit logging break — or roll back — the write it observes: skip when the table
        // isn't migrated yet, and swallow any insert failure (mirrors ErrorLog::capture).
        if (! \Illuminate\Support\Facades\Schema::hasTable((new ActivityLog)->getTable())) {
            return;
        }

        try {
            ActivityLog::create([
                'log_name' => class_basename($this),
                'description' => $description,
                'subject_type' => $this->getMorphClass(),
                'subject_id' => (string) $this->getKey(),
                'causer_type' => auth()->check() ? auth()->user()->getMorphClass() : null,
                'causer_id' => auth()->id() !== null ? (string) auth()->id() : null,
                'properties' => $this->filterLoggedProperties($properties),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function filterLoggedProperties(array $properties): array
    {
        $hidden = array_merge(
            ['password', 'remember_token', 'created_at', 'updated_at'],
            // Any column the model marks $hidden (the generator hides password fields by
            // their real name) or casts as `hashed` — so a `secret:password` hash is never
            // written to the log, not just a column literally named "password".
            $this->getHidden(),
            array_keys(array_filter($this->getCasts(), fn ($cast) => $cast === 'hashed')),
            property_exists($this, 'activityHidden') ? $this->activityHidden : [],
        );

        foreach (['attributes', 'old'] as $bag) {
            if (isset($properties[$bag])) {
                $properties[$bag] = array_diff_key($properties[$bag], array_flip($hidden));
            }
        }

        return $properties;
    }
}
