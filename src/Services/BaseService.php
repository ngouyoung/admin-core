<?php

namespace Ngos\AdminCore\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Common base for admin-core services.
 *
 * Holds the model binding and the foundational query() — the single read
 * chokepoint that CrudService builds on (find/update/delete all flow through
 * it). Override query() in a host base service to apply a cross-cutting scope
 * (e.g. a tenant_id filter) to every list and lookup at once, for both the web
 * admin and the JSON API.
 */
abstract class BaseService
{
    protected Model $model;

    public function query(array|string|null $relation = null): Builder
    {
        return $relation ? $this->model->with($relation) : $this->model->query();
    }
}
