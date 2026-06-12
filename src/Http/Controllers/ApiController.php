<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Base JSON API controller — the web WebController's API twin.
 *
 * Same service + FormRequests (one source of truth for validation/authorization),
 * but returns JsonResource payloads instead of views/redirects. Generated
 * Api\…ApiController subclasses stay thin: they only wire $service, $resource and
 * the request classes. Records are addressed by their public route key (uuid).
 */
abstract class ApiController extends BaseController
{
    /** @var class-string<JsonResource> The resource used to serialise responses. */
    protected string $resource;

    /** Columns a `?search=` term matches (LIKE). Whitelist — empty disables search. */
    protected array $searchable = [];

    /** Columns `?sort=col` / `?sort=-col` may order by. Whitelist — anything else is ignored. */
    protected array $sortable = [];

    /** Columns `?filter[col]=value` may exact-match. Whitelist — anything else is ignored. */
    protected array $filterable = [];

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->service->query();
        $this->applyFilters($query, $request);
        $this->applySearch($query, $request);
        $this->applySort($query, $request);

        $perPage = (int) $request->integer('per_page', (int) config('admin-core.api.per_page', 25));
        $perPage = max(1, min($perPage, (int) config('admin-core.api.max_per_page', 100)));

        return ($this->resource)::collection($query->paginate($perPage)->withQueryString());
    }

    /** `?filter[col]=value` → exact match, only for whitelisted columns (scalar values only). */
    protected function applyFilters(Builder $query, Request $request): void
    {
        foreach ((array) $request->query('filter', []) as $column => $value) {
            // is_scalar guards against array-valued params (?filter[col][]=x), which would
            // bind an array into where() and error.
            if (in_array($column, $this->filterable, true) && is_scalar($value) && $value !== '') {
                $query->where($column, $value);
            }
        }
    }

    /** `?search=term` → OR-LIKE across the searchable columns (grouped so it doesn't leak past filters). */
    protected function applySearch(Builder $query, Request $request): void
    {
        $term = $request->query('search', '');
        if (! is_string($term) || ($term = trim($term)) === '' || $this->searchable === []) {
            return;
        }

        $query->where(function (Builder $q) use ($term) {
            foreach ($this->searchable as $column) {
                $q->orWhere($column, 'like', '%' . $term . '%');
            }
        });
    }

    /** `?sort=col` / `?sort=-col` (desc) → only for whitelisted columns. */
    protected function applySort(Builder $query, Request $request): void
    {
        $sort = $request->query('sort', '');
        if (! is_string($sort) || ($sort = trim($sort)) === '') {
            return;
        }

        $descending = str_starts_with($sort, '-');
        $column = ltrim($sort, '-');

        if (in_array($column, $this->sortable, true)) {
            $query->orderBy($column, $descending ? 'desc' : 'asc');
        }
    }

    public function show(string $id): JsonResource
    {
        return new ($this->resource)($this->service->find($id));
    }

    public function store(): JsonResponse
    {
        $data = app($this->storeRequest)->validated();
        $object = DB::transaction(fn () => $this->service->create($data));

        return (new ($this->resource)($object))->response()->setStatusCode(201);
    }

    public function update(string $id): JsonResource
    {
        $data = app($this->updateRequest)->validated();
        $object = DB::transaction(fn () => $this->service->update($id, $data));

        return new ($this->resource)($object);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json(['data' => true]);
    }
}
