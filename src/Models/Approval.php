<?php

namespace Ngos\AdminCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A pending/decided approval request for an `->requiresApproval()` table action. Holds who asked, what action
 * over which rows (payload), and the decision. Resolved by uuid in routes.
 *
 * @property string $uuid
 * @property string $action
 * @property string|null $resource
 * @property string $handler
 * @property array $payload
 * @property string $status
 * @property string|null $guard
 * @property string|null $note
 * @property string|null $decision_note
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Database\Eloquent\Model|null $requester
 * @property int|string|null $requester_id
 * @property string|null $requester_type
 * @property \Illuminate\Database\Eloquent\Model|null $approver
 * @property int|string|null $approver_id
 * @property string|null $approver_type
 */
class Approval extends Model
{
    protected $fillable = ['action', 'resource', 'handler', 'payload', 'status', 'guard', 'note', 'decision_note', 'decided_at'];

    protected $casts = [
        'payload' => 'array',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Approval $a) => $a->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // withTrashed(): the requester/approver must still resolve after that user is soft-deleted (offboarded)
    // — otherwise the approvals screen shows '—' for a request they filed/decided. MorphTo applies this only
    // to concrete types that use SoftDeletes, so a non-soft-deletable user is unaffected.
    public function requester(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function approver(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /** @param  Builder<Approval>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    /**
     * Scope to the requests filed under one route group's guard, so each portal's inbox lists (and can
     * decide) only its OWN portal's requests. Null/'' = the default guard. Legacy rows that predate the
     * guard column (guard NULL) surface only on the DEFAULT guard's inbox — never on a portal's (fail
     * closed). No-op while the column hasn't been migrated yet: behavior then matches the previous release
     * instead of a SQL error.
     *
     * @param  Builder<Approval>  $query
     */
    public function scopeForGuard(Builder $query, ?string $guard): void
    {
        if (! self::guardColumnPresent()) {
            return;
        }

        $default = (string) config('auth.defaults.guard', 'web');
        $effective = ($guard !== null && $guard !== '') ? $guard : $default;

        $query->where(function (Builder $q) use ($effective, $default) {
            $q->where('guard', $effective);
            if ($effective === $default) {
                $q->orWhereNull('guard');
            }
        });
    }

    /** Whether the guard column exists yet — an install may run the updated package before migrating. */
    public static function guardColumnPresent(): bool
    {
        return Schema::hasColumn((new self)->getTable(), 'guard');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** The captured row ids the action will run over. @return array<int, int|string> */
    public function ids(): array
    {
        return array_values((array) ($this->payload['ids'] ?? []));
    }

    public function label(): string
    {
        return (string) ($this->payload['label'] ?? Str::headline($this->action));
    }
}
