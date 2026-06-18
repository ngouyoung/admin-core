<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /** Route-name base under the route prefix, e.g. 'assessments.users.' */
    protected string $routeBase = '';

    /**
     * Route-name prefix. Defaults to config('admin-core.route.name_prefix') ('admin.'); a
     * resource generated for another portal sets it explicitly (e.g. 'merchant.') so its
     * redirects resolve inside that portal's route group rather than the admin one.
     */
    protected ?string $routePrefix = null;

    protected function view(string $file, array $data = [])
    {
        return view(config('admin-core.views.path_prefix') . $this->viewPath . $file, $data);
    }

    protected function routeName(string $action): string
    {
        return ($this->routePrefix ?? config('admin-core.route.name_prefix')) . $this->routeBase . $action;
    }

    protected function toIndex(?string $message = null): RedirectResponse
    {
        $redirect = redirect()->route($this->routeName('index'));

        return $message === null ? $redirect : $redirect->with('success', $message);
    }

    /**
     * Flash message shown after a write action. Override per resource to customise or
     * translate, e.g. `return __("users.{$action}");` — the layout renders session('success').
     */
    protected function message(string $action): string
    {
        return match ($action) {
            'created' => 'Created successfully.',
            'updated' => 'Updated successfully.',
            'deleted' => 'Deleted successfully.',
            'restored' => 'Restored successfully.',
            default => 'Done.',
        };
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

        return $this->toIndex($this->message('created'));
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

        return $this->toIndex($this->message('updated'));
    }

    public function delete(int|string $id): RedirectResponse
    {
        $this->service->delete($id);

        return $this->toIndex($this->message('deleted'));
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

    /**
     * belongsTo relations whose related `name` is appended to the CSV export as a readable column
     * (e.g. `category` next to `category_id`). Kept alongside the FK so a round-tripped export still
     * imports — the name column isn't fillable, so import ignores it. Generated from the resource's
     * foreign keys; override to change.
     *
     * @var array<int, string>
     */
    protected array $exportRelations = [];

    /**
     * Stream rows to a CSV download. By default every table column plus a readable name per relation
     * (belongsTo → the related name; belongsToMany → the related names joined). Pass `?columns[]=` to
     * export only a chosen subset (the field picker on the Export button) — whitelisted against the real
     * columns/relations, so a bad value can never leak a hidden one.
     */
    public function export(Request $request): StreamedResponse
    {
        // Never export password hashes: drop anything the model marks $hidden, plus any
        // `hashed`-cast column (covers models that predate the generated $hidden).
        $model = $this->service->query()->getModel();
        $secret = array_merge(
            $model->getHidden(),
            array_keys(array_filter($model->getCasts(), fn ($cast) => $cast === 'hashed')),
        );
        // Columns come from the schema, not a fetched row, so the header is correct even on an
        // empty table and we never have to materialise a row just to learn the column names.
        $columns = array_values(array_diff(Schema::getColumnListing($model->getTable()), $secret));
        $relations = $this->exportRelations;

        // Field picker: limit to the requested columns/relations (intersect = whitelist).
        $requested = array_values(array_filter((array) $request->query('columns', []), 'is_string'));
        if ($requested) {
            $columns = array_values(array_intersect($columns, $requested));
            $relations = array_values(array_intersect($relations, $requested));
        }

        $name = trim(str_replace('.', '-', $this->routeBase), '-') . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($columns, $relations) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders accented/non-ASCII text correctly
            // escape: '' = RFC-4180 quoting (double the quotes, no backslash escaping); also
            // silences PHP 8.4's "the $escape parameter must be provided" deprecation.
            fputcsv($out, array_merge($columns, $relations), escape: '');
            // Stream lazily (eager-loading the chosen relations per 1k chunk) so a large table
            // exports with flat memory instead of loading every row up front.
            $this->service->query($relations ?: null)->lazy()->each(function ($row) use ($out, $columns, $relations) {
                $cells = array_map(fn ($c) => $this->csvCell($row->getAttribute($c)), $columns);
                foreach ($relations as $relation) {
                    $related = $row->{$relation};
                    // belongsToMany → a Collection of related models (join their names); belongsTo → one model.
                    $cells[] = $related instanceof \Illuminate\Support\Collection
                        ? $this->csvCell($related->map(fn ($i) => $i->name ?? $i->getKey())->implode(', '))
                        : $this->csvCell($related?->name);
                }
                fputcsv($out, $cells, escape: '');
            });
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
        // Enum-cast attributes arrive as BackedEnum instances; export their value.
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        // Booleans: write 1/0. fputcsv renders false as an empty cell, which the import's
        // boolean rule then rejects (it accepts 0/1/'0'/'1', never ''), so a false wouldn't round-trip.
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // json/array-cast columns come back as arrays — encode them, or fputcsv hits
        // "Array to string conversion" and writes a literal "Array". Round-trips via import.
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

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

        $model = $this->service->query()->getModel();
        $rules = (new $this->storeRequest)->rules();
        // A CSV can't carry an uploaded file, so drop image/file columns from validation: the exported
        // path string would fail the `image`/`file` rule, and the service ignores non-UploadedFile values
        // anyway. validated() then omits them, so they're not imported and the record keeps its file.
        $rules = array_filter($rules, fn ($r) => ! (is_array($r) && (in_array('image', $r, true) || in_array('file', $r, true))));
        $fillable = $model->getFillable();
        // Columns the model casts to array/json — their cells are decoded back from the
        // JSON string the export wrote, so a round-tripped json field imports as an array.
        $arrayColumns = array_keys(array_filter(
            $model->getCasts(),
            fn ($cast) => in_array($cast, ['array', 'json', 'object', 'collection'], true),
        ));

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle, escape: '') ?: [];
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // strip the UTF-8 BOM our export writes
        }

        $imported = 0;
        $errors = [];
        $line = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue; // blank line
            }

            $cells = array_pad(array_slice($cells, 0, count($header)), count($header), null);
            $row = array_intersect_key(array_combine($header, $cells), array_flip($fillable));

            foreach ($arrayColumns as $col) {
                if (isset($row[$col]) && is_string($row[$col]) && $row[$col] !== '') {
                    $decoded = json_decode($row[$col], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row[$col] = $decoded;
                    }
                }
            }

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

        // Reflect the outcome in the flash level (the layout renders success/error/warning): all rows in →
        // success, none in → error, a partial import → warning. (A green "success" on a failed import lied.)
        $level = match (true) {
            $errors === [] => 'success',
            $imported === 0 => 'error',
            default => 'warning',
        };

        return back()->with($level, $message);
    }

    /**
     * Download a blank CSV (header row only) of the columns a user can actually import, so they
     * don't have to guess the fields. It lists the model's fillable columns minus password/hashed
     * ones and any image/file column (a CSV can't carry a file — same exclusions import() applies).
     */
    public function importTemplate(): StreamedResponse
    {
        $model = $this->service->query()->getModel();
        $rules = (new $this->storeRequest)->rules();
        $secret = array_merge(
            $model->getHidden(),
            array_keys(array_filter($model->getCasts(), fn ($cast) => $cast === 'hashed')),
        );
        // image/file columns can't be imported from a CSV, so leave them out of the template.
        $files = array_keys(array_filter(
            $rules,
            fn ($r) => is_array($r) && (in_array('image', $r, true) || in_array('file', $r, true)),
        ));
        $columns = array_values(array_diff($model->getFillable(), $secret, $files));
        $name = trim(str_replace('.', '-', $this->routeBase), '-') . '-import-template.csv';

        return response()->streamDownload(function () use ($columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel)
            fputcsv($out, $columns, escape: '');
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
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

        return redirect()->route($this->routeName('trash'))->with('success', $this->message('restored'));
    }

    public function forceDelete(int|string $id): RedirectResponse
    {
        $this->service->forceDelete($id);

        return redirect()->route($this->routeName('trash'))->with('success', $this->message('deleted'));
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
