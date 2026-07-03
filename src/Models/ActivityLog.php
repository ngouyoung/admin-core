<?php

namespace Ngos\AdminCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An audit entry written by the LogsActivity trait: what happened, to which
 * record (subject), by whom (causer), and the changed attributes (properties).
 */
class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
    ];

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
