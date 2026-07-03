<?php

namespace Ngos\AdminCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An audit entry written by the LogsActivity trait: what happened, to which
 * record (subject), by whom (causer), and the changed attributes (properties).
 */
class ActivityLog extends Model
{
    use MassPrunable;

    protected $table = 'activity_logs';

    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
    ];

    /**
     * Rows older than `admin-core.activity_log.retention_days` are pruned by `model:prune` (the package
     * schedules it daily when retention is set — see AdminCoreServiceProvider). MassPrunable = one DELETE,
     * no model events. Defaults to 0 = keep FOREVER (audit trails are usually retained), so pruning is
     * opt-in and never silently destroys audit history.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = (int) config('admin-core.activity_log.retention_days', 0);

        return $days > 0
            ? static::query()->where('created_at', '<=', now()->subDays($days))
            : static::query()->whereRaw('1 = 0');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): MorphTo
    {
        // withTrashed(): an audit trail must still name WHO acted after that user is soft-deleted (offboarded)
        // — otherwise every log they caused silently reads as 'system'. MorphTo applies this only to concrete
        // causer types that actually use SoftDeletes, so a non-soft-deletable causer is unaffected.
        return $this->morphTo()->withTrashed();
    }
}
