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
        static::deleted(function ($model) {
            // forceDelete() on a SoftDeletes model also fires `deleted` (with isForceDeleting() true);
            // let the forceDeleted hook record that case so a permanent delete isn't logged as a soft one.
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                return;
            }
            $model->recordActivity('deleted');
        });
        // 'restored' / 'forceDeleted' only ever fire for SoftDeletes models; harmless (never dispatched)
        // otherwise. Without them, un-deleting or permanently deleting a record left no (accurate) audit trail.
        // Use registerModelEvent, NOT the static::restored()/forceDeleted() helpers: those helpers exist only on
        // a SoftDeletes model, so on a plain --audit model (e.g. an --audit --read-only resource, where
        // soft-deletes is dropped) they fall through __callStatic → `new static` → a re-entrant boot
        // (LogicException). registerModelEvent just wires the listener; the event never fires without SoftDeletes.
        static::registerModelEvent('restored', fn ($model) => $model->recordActivity('restored'));
        static::registerModelEvent('forceDeleted', fn ($model) => $model->recordActivity('force_deleted'));
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
        $properties = $this->filterLoggedProperties($properties);

        // An 'updated' event whose only changed columns are hidden (touch() bumping updated_at, a
        // remember_token rotation, a $touches parent-timestamp bump) filters down to empty old/attributes — a
        // blank audit row with zero auditable information. Skip it: it would only dilute the real trail and grow
        // unbounded under the keep-forever default retention. (created/deleted/restored still log — those are
        // auditable facts even with no visible attribute diff.)
        if ($description === 'updated' && ($properties['old'] ?? []) === [] && ($properties['attributes'] ?? []) === []) {
            return;
        }

        // Never let audit logging break — or roll back — the write it observes: skip when the table
        // isn't migrated yet, and swallow any insert failure (mirrors ErrorLog::capture).
        if (! \Illuminate\Support\Facades\Schema::hasTable((new ActivityLog)->getTable())) {
            return;
        }

        try {
            // Inside the try: resolving the causer walks the configured guards, and a portal guard named in
            // admin-core config but missing from auth.php throws "Auth guard [x] is not defined" — which, left
            // uncaught here, would roll back the very write this observes. Audit is best-effort; the write wins.
            $causer = $this->resolveCauser();

            ActivityLog::create([
                'log_name' => class_basename($this),
                'description' => $description,
                'subject_type' => $this->getMorphClass(),
                'subject_id' => (string) $this->getKey(),
                'causer_type' => $causer?->getMorphClass(),
                'causer_id' => $causer !== null ? (string) $causer->getAuthIdentifier() : null,
                'properties' => $properties, // already filtered above
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The user who caused this change, resolved from whichever guard actually handled the request — so a
     * multi-portal action is attributed to the portal's user, not the default ('web') guard's. Falls back to
     * the default guard; for a single-guard app this is exactly auth()->user().
     */
    private function resolveCauser(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        // Delegated to the single canonical resolver (AR-1) — this used to hand-roll the portal-first guard walk
        // that five other loci copy-pasted with the INVERSE order; now every locus resolves the identical actor.
        return \Ngos\AdminCore\Support\ActorResolver::user();
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
