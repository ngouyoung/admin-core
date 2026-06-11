<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Base JSON API controller — the web CrudController's API twin.
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

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->integer('per_page', (int) config('admin-core.api.per_page', 25));

        return ($this->resource)::collection($this->service->query()->paginate($perPage));
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
