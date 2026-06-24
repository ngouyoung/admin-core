<?php

namespace Ngos\AdminCore\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Base service every resource service extends — the shared business core used by
 * both the web (WebController) and JSON API (ApiController) channels.
 *
 * Receives plain arrays (never the HTTP Request) so it can be driven from
 * controllers, jobs, commands or tests, and signals "not found" with a native
 * ModelNotFoundException (→ 404) rather than a magic string.
 *
 * query() is the single read chokepoint — find, update, delete, trash, restore,
 * force-delete and reorder all flow through it, so a single query() override in a
 * host base service (e.g. a tenant_id scope) covers every read AND write across the
 * admin and the API — you can't reach a row outside your scope by any path.
 */
abstract class BaseService
{
    protected Model $model;

    public function query(array|string|null $relation = null): Builder
    {
        return $relation ? $this->model->with($relation) : $this->model->query();
    }

    public function find(int|string $id): Model
    {
        // Resolve by the model's route key (its public 'uuid' under the hybrid key
        // strategy, or 'id' for a plain bigint model) — never the raw primary key.
        // Routed through query() so a query() override (e.g. tenant scope) applies.
        return $this->query()->where($this->model->getRouteKeyName(), $id)->firstOrFail();
    }

    public function create(array $data): Model
    {
        return $this->model->create($data);
    }

    public function update(int|string $id, array $data): Model
    {
        $model = $this->find($id);
        $model->update($data);

        return $model;
    }

    public function delete(int|string $id): void
    {
        $this->find($id)->delete();
    }

    // -- Soft deletes (only meaningful when the model uses SoftDeletes) --

    public function trashedQuery(array|string|null $relation = null): Builder
    {
        // Route through query() so a query() override (e.g. a tenant scope) also covers the trash view.
        return $this->query($relation)->onlyTrashed();
    }

    public function restore(int|string $id): void
    {
        $this->findTrashed($id)->restore();
    }

    public function forceDelete(int|string $id): void
    {
        $this->findTrashed($id)->forceDelete();
    }

    /** Look up a soft-deleted record by route key, honouring any query() scope (so you can't
        restore/force-delete a record outside your scope — e.g. another tenant's). */
    protected function findTrashed(int|string $id): Model
    {
        return $this->query()->onlyTrashed()->where($this->model->getRouteKeyName(), $id)->firstOrFail();
    }

    /**
     * Reconcile a hasMany relation against a posted set of rows — the master-detail / repeater pattern
     * (a Purchase + its line items, a Product + its units). Rows carrying an `id` are updated, new rows are
     * created, and rows no longer present are deleted. Pass $rows === null (the form didn't own the block,
     * e.g. an API/import call that omitted it) to leave the children untouched.
     *
     * Without $attributes each row is persisted as-is (minus its `id`) — so mass-assignment is bounded by
     * the child model's `$fillable` (give every line-item model an explicit `$fillable`, never `$guarded = []`).
     * Pass $attributes to whitelist or derive the columns explicitly — return null from it to skip a row:
     *
     *   $this->syncHasMany($purchase, 'items', $rows, fn ($r) => empty($r['product_id']) ? null : [
     *       'product_id' => $r['product_id'],
     *       'qty'        => $r['qty'] ?? 0,
     *   ]);
     *
     * Each row's `id` (when present) must be the child's primary-key value (what the repeater posts), which
     * `whereKey()` resolves; new rows omit it.
     *
     * @return array<int, mixed> the kept child keys (created + updated)
     */
    protected function syncHasMany(Model $parent, string $relation, ?array $rows, ?callable $attributes = null): array
    {
        if ($rows === null) {
            return [];
        }

        $keep = [];
        foreach ($rows as $row) {
            $attrs = $attributes ? $attributes($row) : collect($row)->except('id')->all();
            if ($attrs === null) {
                continue; // the transform opted to skip this row (e.g. blank)
            }
            $id = $row['id'] ?? null;
            $existing = $id ? $parent->{$relation}()->whereKey($id)->first() : null;
            if ($existing) {
                $existing->update($attrs);
                $keep[] = $existing->getKey();
            } else {
                $keep[] = $parent->{$relation}()->create($attrs)->getKey();
            }
        }

        // Anything not in the submission is removed (an empty $rows ⇒ all children cleared).
        $parent->{$relation}()->when($keep !== [], fn ($q) => $q->whereKeyNot($keep))->delete();

        return $keep;
    }

    /** Persist a new order: each (route-key) id's `sort` becomes its 1-based position. */
    public function reorder(array $ids): void
    {
        $key = $this->model->getRouteKeyName();
        // One transaction so a mid-loop failure can't leave a half-renumbered (corrupt) order.
        DB::transaction(function () use ($ids, $key) {
            foreach (array_values($ids) as $position => $id) {
                // Scoped through query() so a reorder can't write `sort` onto rows outside the scope.
                $this->query()->where($key, $id)->update(['sort' => $position + 1]);
            }
        });
    }
}
