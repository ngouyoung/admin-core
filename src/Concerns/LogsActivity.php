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

        ActivityLog::create([
            'log_name' => class_basename($this),
            'description' => $description,
            'subject_type' => $this->getMorphClass(),
            'subject_id' => (string) $this->getKey(),
            'causer_type' => auth()->check() ? auth()->user()->getMorphClass() : null,
            'causer_id' => auth()->id() !== null ? (string) auth()->id() : null,
            'properties' => $this->filterLoggedProperties($properties),
        ]);
    }

    protected function filterLoggedProperties(array $properties): array
    {
        $hidden = array_merge(
            ['password', 'remember_token', 'created_at', 'updated_at'],
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
