<?php

namespace Ngos\AdminCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
 * @property string|null $note
 * @property string|null $decision_note
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Database\Eloquent\Model|null $requester
 * @property \Illuminate\Database\Eloquent\Model|null $approver
 */
class Approval extends Model
{
    protected $fillable = ['action', 'resource', 'handler', 'payload', 'status', 'note', 'decision_note', 'decided_at'];

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

    public function requester(): MorphTo
    {
        return $this->morphTo();
    }

    public function approver(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param  Builder<Approval>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
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
