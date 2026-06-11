<?php

namespace Ngos\AdminCore\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Base CRUD service. Receives plain arrays (never the HTTP Request) so it can be
 * driven from controllers, jobs, commands or tests, and signals "not found" with
 * a native ModelNotFoundException (→ 404) rather than a magic string.
 *
 * The model binding + foundational query() live on {@see BaseService}, so a host
 * base service can scope every read (incl. find/update/delete) in one place.
 */
abstract class CrudService extends BaseService
{
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
        $query = $relation ? $this->model->with($relation) : $this->model->newQuery();

        return $query->onlyTrashed();
    }

    public function restore(int|string $id): void
    {
        $this->model->onlyTrashed()->where($this->model->getRouteKeyName(), $id)->firstOrFail()->restore();
    }

    public function forceDelete(int|string $id): void
    {
        $this->model->onlyTrashed()->where($this->model->getRouteKeyName(), $id)->firstOrFail()->forceDelete();
    }

    /** Persist a new order: each (route-key) id's `sort` becomes its 1-based position. */
    public function reorder(array $ids): void
    {
        $key = $this->model->getRouteKeyName();
        foreach (array_values($ids) as $position => $id) {
            $this->model->newQuery()->where($key, $id)->update(['sort' => $position + 1]);
        }
    }
}
