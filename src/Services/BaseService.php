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
