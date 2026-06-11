<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\DataTables;

/**
 * Config-driven base controller for the web (HTML) channel — the API twin is
 * {@see ApiController}. Renders Blade views + redirects, plus DataTables
 * (getData), export/import and bulk delete.
 *
 * - Validation/authorization come from FormRequest classes resolved per action
 *   ($storeRequest / $updateRequest), so there is no per-action try/catch.
 * - Writes run inside DB::transaction(), which rolls back on ANY throwable.
 * - "Not found" is a native ModelNotFoundException (→ errors/404), no string sentinels.
 * - View/route names are resolved from config('admin-core.*'), not hardcoded prefixes.
 */
abstract class WebController extends BaseController
{
    /** Blade dot-path under config('admin-core.views.path_prefix'), e.g. 'assessments.users.' */
    protected string $viewPath = '';

    /** Route-name base under config('admin-core.route.name_prefix'), e.g. 'assessments.users.' */
    protected string $routeBase = '';

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

    public function show(int|string $id)
    {
        return $this->view('show', ['object' => $this->service->find($id)]);
    }

    public function edit(int|string $id)
    {
        return $this->view('edit', ['object' => $this->service->find($id)]);
    }

    public function update(int|string $id): RedirectResponse
    {
        $data = app($this->updateRequest)->validated();
        DB::transaction(fn () => $this->service->update($id, $data));

        return $this->toIndex();
    }

    public function delete(int|string $id): RedirectResponse
    {
        $this->service->delete($id);

        return $this->toIndex();
    }

    public function ajaxDelete(int|string $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json(['code' => 200, 'message' => 'OK', 'data' => true]);
    }

    public function getData($relation = null)
    {
        return DataTables::of($this->service->query($relation));
    }

    /** Stream every row to a CSV download (all table columns). */
    public function export(): StreamedResponse
    {
        $rows = $this->service->query()->get();
        $columns = $rows->isEmpty() ? [] : array_keys($rows->first()->getAttributes());
        $name = trim(str_replace('.', '-', $this->routeBase), '-') . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders accented/non-ASCII text correctly
            fputcsv($out, $columns);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($c) => $this->csvCell($row->getAttribute($c)), $columns));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Neutralise CSV / formula injection: a cell whose text begins with =, +, -,
     * @, tab or CR is run as a formula by Excel/Sheets. Prefixing a single quote
     * makes the app treat it as text. Genuine numbers (incl. negatives) are left
     * alone so the export stays usable.
     */
    protected function csvCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== ''
            && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            && ! is_numeric($value)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Import rows from an uploaded CSV (same shape as the export). The header row
     * maps cells to columns; each row is validated against the store rules and
     * only fillable keys are kept, so a round-tripped export (with id/uuid/dates)
     * imports cleanly. Invalid rows are skipped and reported, not fatal.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $rules = (new $this->storeRequest)->rules();
        $fillable = $this->service->query()->getModel()->getFillable();

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle) ?: [];
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // strip the UTF-8 BOM our export writes
        }

        $imported = 0;
        $errors = [];
        $line = 1;

        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue; // blank line
            }

            $cells = array_pad(array_slice($cells, 0, count($header)), count($header), null);
            $row = array_intersect_key(array_combine($header, $cells), array_flip($fillable));

            $validator = Validator::make($row, $rules);
            if ($validator->fails()) {
                $errors[] = "Row {$line}: " . $validator->errors()->first();
                continue;
            }

            DB::transaction(fn () => $this->service->create($validator->validated()));
            $imported++;
        }
        fclose($handle);

        $message = "Imported {$imported} row(s).";
        if ($errors) {
            $message .= ' Skipped ' . count($errors) . ': ' . implode(' | ', array_slice($errors, 0, 5))
                . (count($errors) > 5 ? ' …' : '');
        }

        return back()->with('success', $message);
    }

    /** Delete every selected row (soft delete if the model uses SoftDeletes). */
    public function bulkDelete(Request $request): JsonResponse
    {
        $ids = array_filter((array) $request->input('ids', []));
        DB::transaction(function () use ($ids) {
            foreach ($ids as $id) {
                $this->service->delete($id);
            }
        });

        return response()->json(['code' => 200, 'deleted' => count($ids)]);
    }

    // -- Soft deletes (routed only for resources generated with --soft-deletes) --

    public function trash()
    {
        return $this->view('trash', ['items' => $this->service->trashedQuery()->latest('deleted_at')->get()]);
    }

    public function restore(int|string $id): RedirectResponse
    {
        $this->service->restore($id);

        return redirect()->route($this->routeName('trash'));
    }

    public function forceDelete(int|string $id): RedirectResponse
    {
        $this->service->forceDelete($id);

        return redirect()->route($this->routeName('trash'));
    }

    /** Persist a drag-and-drop reorder (resources generated with --sortable). */
    public function reorder(Request $request): JsonResponse
    {
        $this->service->reorder(array_filter((array) $request->input('ids', [])));

        return response()->json(['code' => 200]);
    }

    /** Render a list of Bootstrap-5 badges for a DataTables cell (markup lives in a Blade view). */
    protected function badges(iterable $items, string $variant = 'success'): string
    {
        return view('admin-core::datatable.badges', compact('items', 'variant'))->render();
    }

    /**
     * Render the row's kebab (⋯) menu of view/edit/delete actions.
     *
     * Extra resource-specific items can be injected as a list of:
     *   ['label' => 'Change Password', 'url' => route(...), 'icon' => 'bi bi-key',
     *    'can' => 'change-password-user', 'class' => 'text-danger']
     * ('icon', 'can' and 'class' are optional). They render above Edit/Delete.
     *
     * @param  array<int, array<string, string>>  $extra
     */
    protected function actions($model, string $resource, array $extra = []): string
    {
        return view('admin-core::datatable.actions', [
            'model' => $model,
            'base' => $this->routeName(''),
            'resource' => $resource,
            'extra' => $extra,
        ])->render();
    }
}
