<?php

namespace Ngos\AdminCore\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Base CRUD service. Receives plain arrays (never the HTTP Request) so it can be
 * driven from controllers, jobs, commands or tests, and signals "not found" with
 * a native ModelNotFoundException (→ 404) rather than a magic string.
 */
abstract class CrudService
{
    protected Model $model;

    public function query(array|string|null $relation = null): Builder
    {
        return $relation ? $this->model->with($relation) : $this->model->query();
    }

    public function find(int|string $id): Model
    {
        return $this->model->findOrFail($id);
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
}
