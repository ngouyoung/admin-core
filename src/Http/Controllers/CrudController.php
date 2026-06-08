<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Ngos\AdminCore\Services\CrudService;
use Yajra\DataTables\DataTables;

/**
 * Config-driven base CRUD controller.
 *
 * - Validation/authorization come from FormRequest classes resolved per action
 *   ($storeRequest / $updateRequest), so there is no per-action try/catch.
 * - Writes run inside DB::transaction(), which rolls back on ANY throwable.
 * - "Not found" is a native ModelNotFoundException (→ errors/404), no string sentinels.
 * - View/route names are resolved from config('admin-core.*'), not hardcoded prefixes.
 */
abstract class CrudController extends Controller
{
    /** Blade dot-path under config('admin-core.views.path_prefix'), e.g. 'assessments.users.' */
    protected string $viewPath = '';

    /** Route-name base under config('admin-core.route.name_prefix'), e.g. 'assessments.users.' */
    protected string $routeBase = '';

    /** CRUD service for this resource. */
    protected CrudService $service;

    /** FormRequest classes — resolved (and validated) when the action runs. */
    protected string $storeRequest;
    protected string $updateRequest;

    protected function view(string $file, array $data = [])
    {
        return view(config('admin-core.views.path_prefix') . $this->viewPath . $file, $data);
    }

    protected function routeName(string $action): string
    {
        return config('admin-core.route.name_prefix') . $this->routeBase . $action;
    }

    protected function toIndex(): RedirectResponse
    {
        return redirect()->route($this->routeName('index'));
    }

    public function index()
    {
        return $this->view('index');
    }

    public function create()
    {
        return $this->view('create');
    }

    public function store(): RedirectResponse
    {
        $data = app($this->storeRequest)->validated();
        DB::transaction(fn () => $this->service->create($data));

        return $this->toIndex();
    }

    public function edit(int $id)
    {
        return $this->view('edit', ['object' => $this->service->find($id)]);
    }

    public function update(int $id): RedirectResponse
    {
        $data = app($this->updateRequest)->validated();
        DB::transaction(fn () => $this->service->update($id, $data));

        return $this->toIndex();
    }

    public function delete(int $id): RedirectResponse
    {
        $this->service->delete($id);

        return $this->toIndex();
    }

    public function ajaxDelete(int $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json(['code' => 200, 'message' => 'OK', 'data' => true]);
    }

    public function getData($relation = null)
    {
        return DataTables::of($this->service->query($relation));
    }

    /** Render a list of Bootstrap-5 badges for a DataTables cell (markup lives in a Blade view). */
    protected function badges(iterable $items, string $variant = 'success'): string
    {
        return view('admin-core::datatable.badges', compact('items', 'variant'))->render();
    }

    /** Render the standard edit/delete action buttons for a DataTables row. */
    protected function actions($model, string $resource): string
    {
        return view('admin-core::datatable.actions', [
            'model' => $model,
            'base' => $this->routeName(''),
            'resource' => $resource,
        ])->render();
    }
}
