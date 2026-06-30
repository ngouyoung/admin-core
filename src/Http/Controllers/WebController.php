<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Ngos\AdminCore\Actions\Action;
use Ngos\AdminCore\Models\Approval;
use Ngos\AdminCore\Notifications\AdminNotification;
use Ngos\AdminCore\States\Transition;
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

    /**
     * Auth guard for the permission checks in generated views (the @can action buttons + the row-action
     * dropdown). Null = the default guard; a portal resource sets it to its guard name so a user
     * authenticated on a non-default guard still sees the buttons their permissions allow.
     */
    protected ?string $guard = null;

    /**
     * Resource slug used to derive action / field permission names (e.g. 'product' → 'mark-paid-product').
     * The generated controller sets it; hand-added actions either set it too or give each action an explicit
     * ->permission(). Empty falls back to the bare action key (still a real, grantable permission).
     */
    protected string $resource = '';

    /** The column that holds a document's state, for the transitions() state machine. */
    protected string $stateColumn = 'status';

    /**
     * States in which a record is read-only — edit and delete are refused (e.g. a posted invoice). Set per
     * resource, e.g. `protected array $lockedStates = ['posted', 'cancelled'];`.
     *
     * @var array<int, string>
     */
    protected array $lockedStates = [];

    protected function view(string $file, array $data = [])
    {
        // Share the guard with every generated view so its @can buttons resolve against the right user.
        return view(config('admin-core.views.path_prefix') . $this->viewPath . $file, $data + ['acGuard' => $this->guard]);
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
            'created' => __('admin-core::admin-core.messages.created'),
            'updated' => __('admin-core::admin-core.messages.updated'),
            'deleted' => __('admin-core::admin-core.messages.deleted'),
            'restored' => __('admin-core::admin-core.messages.restored'),
            default => 'Done.',
        };
    }

    public function index()
    {
        // Pass the bulk-action buttons (permission-filtered) to the view, which forwards them to the
        // data-table component via :actions. (Passed as data, not shared, so a second table on the page
        // can't pick up this resource's actions.) acFilters drives the <x-admin-core::list-filters> bar;
        // acSavedViews/acResource drive its per-user "Views" dropdown.
        return $this->view('index', [
            'acActions' => $this->actionsConfig(),
            'acFilters' => $this->listFilters(),
            'acResource' => $this->resource,
            'acSavedViews' => $this->savedViews(),
        ]);
    }

    /**
     * The current user's saved views for this resource — a named filter set each (the <x-admin-core::list-filters>
     * "Views" dropdown). Empty when the resource has no filters, there's no user, or the table isn't migrated yet
     * (so an install that hasn't run the saved_views migration degrades silently rather than erroring).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function savedViews(): array
    {
        if ($this->resource === '' || $this->listFilters() === [] || auth()->id() === null || ! Schema::hasTable('saved_views')) {
            return [];
        }

        return \Ngos\AdminCore\Models\SavedView::query()
            ->where('user_id', auth()->id())
            ->where('resource', $this->resource)
            ->orderBy('name')
            ->get(['id', 'name', 'filters'])
            ->toArray();
    }

    public function create()
    {
        // Share the field-level deny list so the <x-admin-core::field-guard> wrappers in the form can lock
        // (disable) any field the current user isn't allowed to write.
        view()->share('acDeniedFields', $this->deniedFields());

        return $this->view('create');
    }

    public function store(): RedirectResponse
    {
        $data = $this->stripStateColumn($this->stripDeniedFields(app($this->storeRequest)->validated()));

        if (! $this->claimSubmitToken()) {
            // A repeated submit (double-click / retry) carrying the same one-time token — the first claim won,
            // so report success without creating a duplicate. (Optimistic: in the rare case the first submit is
            // still in-flight and then fails, this reports success though nothing was created — an accepted edge
            // for an admin UI; the user simply re-submits.)
            return $this->toIndex($this->message('created'));
        }

        try {
            DB::transaction(fn () => $this->service->create($data));
        } catch (\Throwable $e) {
            $this->releaseSubmitToken(); // a real failure — let the user retry the same token
            throw $e;
        }

        return $this->toIndex($this->message('created'));
    }

    /**
     * Claim the create form's one-time submit token via an atomic cache put-if-absent. Returns false when this
     * POST repeats one already claimed (so store() short-circuits instead of duplicating). With no token (an
     * older form) or the feature disabled it always returns true — unguarded, exactly the prior behaviour.
     */
    protected function claimSubmitToken(): bool
    {
        $token = $this->submitToken();
        if ($token === null) {
            return true;
        }

        return cache()->add('admin-core:idem:' . $token, true, now()->addMinutes((int) config('admin-core.forms.idempotency_ttl', 5)));
    }

    /** Release a claimed token (after a failed create) so a genuine retry is allowed through. */
    protected function releaseSubmitToken(): void
    {
        if ($token = $this->submitToken()) {
            cache()->forget('admin-core:idem:' . $token);
        }
    }

    private function submitToken(): ?string
    {
        if (! config('admin-core.forms.idempotency', true)) {
            return null;
        }
        $token = request()->input('_idempotency_key');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function show(int|string $id)
    {
        $object = $this->service->find($id);

        // The state-machine buttons available for this record (permission + current-state filtered).
        return $this->view('show', ['object' => $object, 'acTransitions' => $this->transitionsFor($object)]);
    }

    public function edit(int|string $id)
    {
        view()->share('acDeniedFields', $this->deniedFields());

        return $this->view('edit', ['object' => $this->service->find($id)]);
    }

    public function update(int|string $id): RedirectResponse
    {
        // Force the stored value of any field the user may not edit back into the request BEFORE validation —
        // so a `required` rule on a locked field still passes (the form omits it because field-guard disabled
        // it). stripDeniedFields then drops it from the write, so the value is left untouched and a tampered
        // value for a locked field is ignored. The raw (pre-cast) value keeps validation rules happy.
        if (($denied = $this->deniedFields()) !== []) {
            $existing = $this->service->find($id);
            request()->merge(collect($denied)->mapWithKeys(fn ($f) => [$f => $existing->getRawOriginal($f)])->all());
        }

        $this->guardLocked($id); // a posted/cancelled document is read-only
        $data = $this->stripStateColumn($this->stripDeniedFields(app($this->updateRequest)->validated()));
        DB::transaction(fn () => $this->service->update($id, $data));

        return $this->toIndex($this->message('updated'));
    }

    public function delete(int|string $id): RedirectResponse
    {
        $this->guardLocked($id);
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
        $query = $this->service->query($relation);
        $this->applyListFilters($query, request());

        // Column totals for the list footer, computed over the FILTERED set (all pages) — see listAggregates().
        $aggregates = $this->computeListAggregates($query);

        $dataTables = DataTables::of($query);
        if ($aggregates !== []) {
            $dataTables->with('acAggregates', $aggregates);
        }

        return $dataTables;
    }

    /**
     * Declarative column totals shown in the list footer — `['column' => 'sum'|'avg'|'min'|'max'|'count']`, or
     * the long form `['column' => ['fn' => 'sum', 'money' => true, 'currency' => 'KHR']]` to format a money
     * column's sum as an exact {@see \Ngos\AdminCore\Support\Money} (the generator fills money columns in for
     * you). Computed server-side over the filtered set — so the total reflects the active list filters, across
     * every page, not just the visible rows. Default none → no footer. Each declared column is one extra
     * aggregate query per getData hit, so keep the list short.
     *
     * The generator never auto-totals a per-record / multi-currency money column (mixed currencies can't sum to
     * one amount) — and the MANUAL path has no such guard, so don't add one by hand expecting a meaningful total.
     *
     * @return array<string, string|array<string, mixed>>
     */
    protected function listAggregates(): array
    {
        return [];
    }

    /**
     * Run each declared aggregate over the (already filtered) list query and format it for the footer, keyed by
     * column. A money aggregate becomes a formatted string ("៛125,000"); a plain one stays numeric. Returns []
     * when nothing is declared so getData() skips the extra query entirely.
     *
     * @return array<string, string|int|float|null>
     */
    protected function computeListAggregates(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $out = [];
        foreach ($this->listAggregates() as $column => $spec) {
            $def = is_array($spec) ? $spec : ['fn' => $spec];
            $fn = strtolower((string) ($def['fn'] ?? 'sum'));
            $col = (string) $column; // a numeric-string key arrives as an int
            // Whitelist the function + column name so a declared spec can never become arbitrary SQL.
            if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $col)
                || ! in_array($fn, ['sum', 'avg', 'min', 'max', 'count'], true)) {
                continue;
            }

            // (clone) so neither the aggregate nor the DataTables query disturbs the other.
            $value = (clone $query)->{$fn}($col);
            if ($value === null) {
                $out[$col] = null;

                continue;
            }

            $out[$col] = ! empty($def['money'])
                ? \Ngos\AdminCore\Support\Money::fromMinor((int) round((float) $value), $def['currency'] ?? null)->format()
                : $this->numericAggregate($value);
        }

        return $out;
    }

    /**
     * Normalise a raw aggregate value for JSON. The DB driver may hand back an int/float (SQLite) or a numeric
     * STRING (MySQL's SUM/AVG over BIGINT/DECIMAL); cast an integer that fits PHP's int range, but keep a huge
     * one as the digit string so it isn't coerced past PHP_INT_MAX into a lossy float ("1.8e19" garbage). A
     * decimal becomes a float.
     */
    protected function numericAggregate(int|float|string $value): int|float|string
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (preg_match('/^-?\d+$/', $value)) {
            return $value === (string) (int) $value ? (int) $value : $value; // exact int, else the full digits
        }

        return is_numeric($value) ? (float) $value : $value;
    }

    /**
     * Declarative list filters for the <x-admin-core::list-filters> bar — each `['column' => …, 'type' =>
     * 'select'|'date', 'label' => …, 'options'|'source' => …]`. Override per resource (the generator fills it
     * from the fields). The columns here are the whitelist applyListFilters() will apply.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function listFilters(): array
    {
        return [];
    }

    /**
     * Apply the request's `?filter[col]=…` to the list query — only for columns declared in listFilters() (so a
     * crafted param can't filter an arbitrary/sensitive column), per the filter's type:
     *   select  exact match  (enum / boolean / foreign id)                         — filter[col]=value
     *   text    LIKE %…%      (string)                                              — filter[col]=value
     *   date    whereDate ≥/≤ (a non-date bound is skipped, not string-compared)    — filter[col][from|to]
     *   number  ≥/≤ range     (integer/decimal; a money filter's major value is converted to minor units) — filter[col][min|max]
     * yajra's own search/sort/paging still run afterward.
     */
    protected function applyListFilters(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        $declared = collect($this->listFilters())->keyBy('column');
        foreach ((array) $request->query('filter', []) as $column => $value) {
            $def = $declared->get($column);
            if ($def === null) {
                continue; // not a declared, whitelisted filter
            }
            $type = $def['type'] ?? 'select';
            if ($type === 'date') {
                // Only apply a bound that parses as a date — a hand-crafted non-date ("abc") would otherwise be
                // string-compared by the driver and silently match all or no rows.
                $from = is_array($value) ? ($value['from'] ?? null) : null;
                $to = is_array($value) ? ($value['to'] ?? null) : null;
                if (is_string($from) && strtotime($from) !== false) {
                    $query->whereDate($column, '>=', $from);
                }
                if (is_string($to) && strtotime($to) !== false) {
                    $query->whereDate($column, '<=', $to);
                }
            } elseif ($type === 'number') {
                // A money filter's bound is a MAJOR amount ("15.00") — convert to the stored minor units.
                $bound = fn ($v) => empty($def['money'])
                    ? (float) $v
                    : \Ngos\AdminCore\Support\Money::fromMajor($v, $def['currency'] ?? null)->minor;
                $min = is_array($value) ? ($value['min'] ?? null) : null;
                $max = is_array($value) ? ($value['max'] ?? null) : null;
                if (is_scalar($min) && $min !== '' && is_numeric($min)) {
                    $query->where($column, '>=', $bound($min));
                }
                if (is_scalar($max) && $max !== '' && is_numeric($max)) {
                    $query->where($column, '<=', $bound($max));
                }
            } elseif ($type === 'text') {
                if (is_scalar($value) && $value !== '') {
                    $query->where($column, 'like', '%' . $value . '%');
                }
            } elseif (is_scalar($value) && $value !== '') {
                $query->where($column, $value); // select: exact match (enum / boolean / foreign id)
            }
        }
    }

    /** The column shown as each option's label in the Select2 remote source ({@see select()}). */
    protected string $selectLabel = 'name';

    /** Columns the Select2 remote source matches the typed term against (defaults to [$selectLabel]). */
    protected array $selectSearch = [];

    /**
     * Columns a dependent (cascading) select may narrow the remote source by — e.g. ['province_id'] so a
     * Commune dropdown shows only the chosen province's communes. Allowlisted: the client can filter only by
     * these columns (parameter-bound). admin-core:make sets it to the resource's foreign keys.
     */
    protected array $selectFilters = [];

    /**
     * Select2 remote source: search this resource by keyword, return one page of {id, text}.
     *
     * Powers an ajax `<x-admin-core::select :ajax-url="route('...select')">` so a big dropdown searches
     * + pages server-side instead of dumping every row into the HTML. Rides the resource's own query()
     * (global scopes / soft-deletes still apply) and the `list` permission (registered in the same route
     * group as getData). The searchable columns and label come from $selectSearch / $selectLabel — chosen
     * server-side, so the client can never point this at an arbitrary model or column.
     */
    public function select(Request $request): JsonResponse
    {
        $label = $this->selectLabel;
        $columns = $this->selectSearch ?: [$label];
        $term = trim((string) $request->query('term', ''));

        $query = $this->service->query();
        $narrowed = false;

        // Cascading selects: narrow by an allowlisted parent value (e.g. communes where province_id = X).
        foreach ((array) $request->query('filter', []) as $col => $val) {
            if (in_array($col, $this->selectFilters, true) && $val !== '' && $val !== null) {
                $query->where($col, $val);
                $narrowed = true;
            }
        }

        if ($term !== '') {
            $query->where(function ($q) use ($columns, $term) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', '%' . $term . '%');
                }
            });
            $narrowed = true;
        }

        // On an unnarrowed open (the whole table) sort by the indexed primary key — an index range scan + LIMIT
        // instead of a filesort over every row (a big win on large tables). Once narrowed by a search term or a
        // cascade filter the set is small, so sort by the human label for a tidy alphabetical dropdown.
        $sort = $narrowed ? $label : $query->getModel()->getQualifiedKeyName();
        $page = $query->orderBy($sort)->paginate((int) config('admin-core.select.per_page', 20));

        return response()->json([
            'results' => $page->getCollection()->map(fn ($row) => [
                'id' => $row->getKey(),
                'text' => ac_localize($row->{$label}) ?: (string) $row->getKey(),
            ])->values(),
            'pagination' => ['more' => $page->hasMorePages()],
        ]);
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
                    // ac_localize so a translatable related name exports as the localized string, not raw
                    // JSON (belongsTo) or an "Array to string conversion" crash (belongsToMany).
                    $cells[] = $related instanceof \Illuminate\Support\Collection
                        ? $this->csvCell($related->map(fn ($i) => ac_localize($i->name) ?: $i->getKey())->implode(', '))
                        : $this->csvCell(ac_localize($related?->name));
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

        // Money-cast columns arrive as a Money object; export the plain major amount ("15.00") — not the
        // formatted "$15.00" — so a round-tripped export re-imports exactly via the MoneyCast (major → minor).
        if ($value instanceof \Ngos\AdminCore\Support\Money) {
            return $value->major();
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
     * Run the store request's prepareForValidation() on a raw import row, so a CSV import gets the SAME
     * input transforms the web form applies — most importantly Html::clean() on rich-text fields. Without
     * it, imported HTML/JSON is validated and stored unsanitised (stored XSS). Builds a throwaway request
     * from the row and invokes the (protected) hook; a request that doesn't override it is a harmless no-op.
     */
    protected function prepareImportRow(array $row): array
    {
        $request = $this->storeRequest::create('', 'POST', $row);
        $request->setContainer(app());
        (new \ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

        return $request->all();
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

            // Run the store form's prepareForValidation (e.g. Html::clean on rich text) so imported rows are
            // sanitised exactly like web submissions — a CSV must not bypass it into stored XSS.
            $row = array_intersect_key($this->prepareImportRow($row), array_flip($fillable));

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
        $ids = $this->bulkIds($request);
        $key = $this->service->query()->getModel()->getRouteKeyName();
        $deleted = 0;
        // Act only on ids that exist in scope — a stale/foreign id is skipped, not a 404 that aborts the
        // whole batch (the old per-id find()->firstOrFail() did). Routed through the service so soft-delete
        // + activity logging still fire. Locked-state records are excluded, like single delete() refuses them.
        DB::transaction(function () use ($ids, $key, &$deleted) {
            $query = $this->excludeLocked($this->service->query()->whereIn($key, $ids));
            foreach ($query->pluck($key) as $id) {
                $this->service->delete($id);
                $deleted++;
            }
        });

        return response()->json(['code' => 200, 'deleted' => $deleted]);
    }

    /**
     * Validate + cap a bulk id payload: an unbounded `ids` array is a mass-write / DoS vector. Returns the
     * non-empty ids.
     */
    protected function bulkIds(Request $request): array
    {
        $request->validate(['ids' => ['array', 'max:1000']]);

        // Keep only non-empty scalars — a nested-array / object element would otherwise reach whereIn() and 500.
        return array_values(array_filter((array) $request->input('ids', []), fn ($v) => is_scalar($v) && $v !== ''));
    }

    // -- Soft deletes (routed only for resources generated with --soft-deletes) --

    public function trash()
    {
        return $this->view('trash', ['items' => $this->service->trashedQuery()->latest('deleted_at')->paginate(config('admin-core.pagination', 50))]);
    }

    public function restore(int|string $id): RedirectResponse
    {
        $this->guardLockedTrashed($id);
        $this->service->restore($id);

        return redirect()->route($this->routeName('trash'))->with('success', $this->message('restored'));
    }

    public function forceDelete(int|string $id): RedirectResponse
    {
        $this->guardLockedTrashed($id); // a locked document can't be permanently destroyed either
        $this->service->forceDelete($id);

        return redirect()->route($this->routeName('trash'))->with('success', $this->message('deleted'));
    }

    /** Restore every selected trashed row. */
    public function bulkRestore(Request $request): JsonResponse
    {
        $ids = $this->bulkIds($request);
        $key = $this->service->query()->getModel()->getRouteKeyName();
        $restored = 0;
        DB::transaction(function () use ($ids, $key, &$restored) {
            $query = $this->excludeLocked($this->service->trashedQuery()->whereIn($key, $ids));
            foreach ($query->pluck($key) as $id) {
                $this->service->restore($id);
                $restored++;
            }
        });

        return response()->json(['code' => 200, 'restored' => $restored]);
    }

    /** Permanently delete every selected trashed row. */
    public function bulkForceDelete(Request $request): JsonResponse
    {
        $ids = $this->bulkIds($request);
        $key = $this->service->query()->getModel()->getRouteKeyName();
        $deleted = 0;
        DB::transaction(function () use ($ids, $key, &$deleted) {
            $query = $this->excludeLocked($this->service->trashedQuery()->whereIn($key, $ids));
            foreach ($query->pluck($key) as $id) {
                $this->service->forceDelete($id);
                $deleted++;
            }
        });

        return response()->json(['code' => 200, 'deleted' => $deleted]);
    }

    /** Persist a drag-and-drop reorder (resources generated with --sortable). */
    public function reorder(Request $request): JsonResponse
    {
        $this->service->reorder($this->bulkIds($request));

        return response()->json(['code' => 200]);
    }

    /** Render a list of Bootstrap-5 badges for a DataTables cell (markup lives in a Blade view). */
    protected function badges(iterable $items, string $variant = 'success'): string
    {
        return view('admin-core::datatable.badges', compact('items', 'variant'))->render();
    }

    /**
     * Render an avatar cell for a DataTables column — the stored image, or a colour +
     * initials fallback (via the avatar component, so a list cell matches the avatar
     * shown everywhere else). $avatarAttr is the model's image column (null → always
     * initials); $nameAttr drives the initials.
     */
    protected function avatar($model, string $nameAttr = 'name', ?string $avatarAttr = 'avatar', int $size = 32): string
    {
        $stored = $avatarAttr ? ($model->{$avatarAttr} ?? null) : null;

        return view('admin-core::datatable.avatar', [
            'src' => $stored ? \Ngos\AdminCore\Support\Media::url($stored) : null,
            'name' => (string) ($model->{$nameAttr} ?? ''),
            'size' => $size,
        ])->render();
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
            'guard' => $this->guard,
            // Declared per-row actions (permission-filtered) rendered as POST buttons in the kebab menu.
            'rowActions' => $this->rowActionsFor($model),
        ])->render();
    }

    // -- Declarative table actions (bulk + per-row) -----------------------------------------------------

    /**
     * Declarative table actions. Override per resource — each Action drives both a bulk-toolbar button and a
     * per-row menu item from one declaration (the package wires the route, permission, confirm and toast):
     *
     *   return [Action::make('mark-paid')->confirm()->handle(fn ($records) => $records->each->update([...]))];
     *
     * @return array<int, Action>
     */
    protected function resourceActions(): array
    {
        return [];
    }

    /** The signed-in user on this controller's guard (the same guard the generated views check). */
    protected function actingUser()
    {
        return auth()->guard($this->guard)->user();
    }

    /** Is the current user allowed to run this action? Ungated actions are always allowed. */
    protected function canRunAction(Action $action): bool
    {
        if (! config('admin-core.permission.enabled')) {
            return true;
        }

        $permission = $action->resolvePermission($this->resource);

        return $permission === null || (bool) $this->actingUser()?->can($permission);
    }

    /**
     * Run a declared action over the selected rows. The permission is enforced HERE (server-side) — the UI
     * only hides buttons; this is the real guard, so a hand-crafted POST can't bypass it.
     */
    public function runAction(Request $request, string $action): JsonResponse
    {
        $resolved = $this->resolveAction($action);
        abort_if($resolved === null, 404);
        abort_unless($this->canRunAction($resolved), 403);

        $ids = $this->bulkIds($request);

        // requiresApproval: a requester who may run it but can't approve it files a pending request instead of
        // executing. (No-op when permissions are off — there's no approver to route it to.)
        if ($resolved->needsApproval() && config('admin-core.permission.enabled') && ! $this->canApprove($resolved)) {
            $this->createApproval($resolved, $ids, (string) $request->input('note', ''));

            return response()->json([
                'code' => 202,
                'pending' => true,
                'message' => __('admin-core::admin-core.toast.action_pending'),
            ], 202);
        }

        return response()->json(['code' => 200] + $this->applyAction($resolved, $ids));
    }

    /** Find a declared action by key, or null. */
    protected function resolveAction(string $key): ?Action
    {
        foreach ($this->resourceActions() as $candidate) {
            if ($candidate->key() === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Execute an action over the given ids and return {affected, message}. Ids resolve through the resource
     * query so global scopes / soft-deletes / tenancy still apply (a foreign id is just absent from the set).
     *
     * @param  array<int, int|string>  $ids
     * @return array{affected: int, message: string}
     */
    protected function applyAction(Action $action, array $ids): array
    {
        $key = $this->service->query()->getModel()->getRouteKeyName();
        $records = $this->service->query()->whereIn($key, $ids)->get();

        $result = null;
        DB::transaction(function () use ($action, $records, &$result) {
            $result = $action->run($records);
        });

        return [
            'affected' => $records->count(),
            'message' => $result['message'] ?? $action->resolveSuccess($records->count()),
        ];
    }

    /**
     * Re-run an approved action over its captured ids. Called by the approvals inbox once an approver decides —
     * NOT routed, so it isn't reachable directly over HTTP (the inbox enforces the approve permission).
     *
     * @param  array<int, int|string>  $ids
     * @return array{affected: int, message: string}
     */
    public function applyApprovedAction(string $actionKey, array $ids): array
    {
        $action = $this->resolveAction($actionKey);
        abort_if($action === null, 404);

        return $this->applyAction($action, $ids);
    }

    /** The permission that lets a user approve (and so run directly) this action: `approve-{key}-{resource}`. */
    protected function approvePermission(Action $action): string
    {
        return 'approve-' . $this->permissionBase($action->key());
    }

    /** The `{action}-{resource}` permission base for an action key (or the bare key when no resource is set). */
    protected function permissionBase(string $actionKey): string
    {
        return $this->resource === ''
            ? $actionKey
            : str_replace(['{action}', '{resource}'], [$actionKey, $this->resource], (string) config('admin-core.permission.pattern', '{action}-{resource}'));
    }

    /** May the current user approve this action (and therefore run it directly)? */
    protected function canApprove(Action $action): bool
    {
        return (bool) $this->actingUser()?->can($this->approvePermission($action));
    }

    /**
     * File a pending approval for an action the requester may run but not approve, then notify the approvers.
     *
     * @param  array<int, int|string>  $ids
     */
    protected function createApproval(Action $action, array $ids, string $note = ''): Approval
    {
        $approval = new Approval([
            'action' => $action->key(),
            'resource' => $this->resource,
            'handler' => static::class,
            'payload' => ['ids' => array_values($ids), 'label' => $action->resolveLabel()],
            'status' => 'pending',
            'note' => $note !== '' ? $note : null,
        ]);
        if ($user = $this->actingUser()) {
            $approval->requester()->associate($user);
        }
        $approval->save();

        $this->notifyApprovers($action, $approval);

        return $approval;
    }

    /** Notify every user who can approve this action (best-effort: needs a Spatie HasRoles user model). */
    protected function notifyApprovers(Action $action, Approval $approval): void
    {
        $model = config('auth.providers.users.model');
        if (! is_string($model) || ! method_exists($model, 'scopePermission')) {
            return; // host user model isn't Spatie-permissioned — the inbox still surfaces the request
        }

        try {
            $approvers = $model::permission($this->approvePermission($action))->get();
        } catch (\Throwable) {
            return;
        }

        $inboxRoute = config('admin-core.route.name_prefix', 'admin.') . 'approvals.index';
        $url = Route::has($inboxRoute) ? route($inboxRoute) : null;

        foreach ($approvers as $approver) {
            if (method_exists($approver, 'notify')) {
                $approver->notify(new AdminNotification(
                    title: __('admin-core::admin-core.approvals.notify_request_title'),
                    message: __('admin-core::admin-core.approvals.notify_request_message', ['label' => $approval->label()]),
                    url: $url,
                    icon: 'bi-check2-square',
                ));
            }
        }
    }

    /**
     * The bulk-scoped actions the current user may run, serialised for the datatable config.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function actionsConfig(): array
    {
        if (! Route::has($this->routeName('action'))) {
            return [];
        }

        $config = [];
        foreach ($this->resourceActions() as $action) {
            if ($action->isBulk() && $this->canRunAction($action)) {
                $config[] = $action->toArray(route($this->routeName('action'), ['action' => $action->key()]));
            }
        }

        return $config;
    }

    /**
     * The per-row actions the current user may run on this model, as kebab-menu button descriptors.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rowActionsFor($model): array
    {
        if (! Route::has($this->routeName('action'))) {
            return [];
        }

        $items = [];
        foreach ($this->resourceActions() as $action) {
            if ($action->isRow() && $this->canRunAction($action)) {
                $items[] = [
                    'label' => $action->resolveLabel(),
                    'icon' => $action->getIcon() ?? 'bi bi-lightning',
                    'url' => route($this->routeName('action'), ['action' => $action->key()]),
                    'id' => $model->getRouteKey(),
                    'confirm' => $action->resolveConfirm(),
                ];
            }
        }

        return $items;
    }

    // -- Field-level permissions ------------------------------------------------------------------------

    /**
     * Declarative field-level permissions: field => permission required to write it. A user lacking the
     * permission can neither see the field (it's hidden in the form) nor set it (stripped on write). Override:
     *
     *   return ['status' => 'change-status-order', 'cost' => 'edit-cost-product'];
     *
     * @return array<string, string>
     */
    protected function fieldPermissions(): array
    {
        return [];
    }

    /**
     * Remove input the current user isn't allowed to write — the server-side guard, so even a hand-crafted
     * POST can't set a protected field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripDeniedFields(array $data): array
    {
        foreach ($this->deniedFields() as $field) {
            unset($data[$field]);
        }

        return $data;
    }

    /**
     * The field names the current user may NOT write (the UI half: shared with create/edit so the form hides
     * them; the write half is stripDeniedFields()).
     *
     * @return array<int, string>
     */
    protected function deniedFields(): array
    {
        if (! config('admin-core.permission.enabled')) {
            return [];
        }

        $denied = [];
        foreach ($this->fieldPermissions() as $field => $permission) {
            if (! $this->actingUser()?->can($permission)) {
                $denied[] = $field;
            }
        }

        return $denied;
    }

    // -- Document state machine (transitions) -----------------------------------------------------------

    /**
     * The document lifecycle. Override per resource — each Transition is a button on the show page that moves
     * the record between states (the package wires the route, permission, confirm and the atomic change):
     *
     *   return [Transition::make('post')->from('confirmed')->to('posted')->handle(fn ($r) => $r->postToStock())];
     *
     * @return array<int, Transition>
     */
    protected function transitions(): array
    {
        return [];
    }

    /** Find a declared transition by key, or null. */
    protected function resolveTransition(string $key): ?Transition
    {
        foreach ($this->transitions() as $candidate) {
            if ($candidate->key() === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /** May the current user run this transition? (Permission enforced here, server-side.) */
    protected function canTransition(Transition $transition): bool
    {
        if (! config('admin-core.permission.enabled')) {
            return true;
        }

        $permission = $transition->resolvePermission($this->resource);

        return $permission === null || (bool) $this->actingUser()?->can($permission);
    }

    /**
     * The transitions available for a record's current state that the current user may run — as button
     * descriptors for the show page.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function transitionsFor($record): array
    {
        if (! Route::has($this->routeName('transition'))) {
            return [];
        }

        $current = (string) ($record->{$this->stateColumn} ?? '');
        $items = [];
        foreach ($this->transitions() as $transition) {
            if ($transition->appliesTo($current) && $this->canTransition($transition) && $transition->passesGuard($record)) {
                $items[] = $transition->toArray(
                    route($this->routeName('transition'), [$record->getRouteKey(), $transition->key()])
                );
            }
        }

        return $items;
    }

    /**
     * Run a state transition. The whole change is atomic — the record is locked, its current state re-checked
     * under the lock, the side-effect run, and the state advanced, all in one transaction. So a concurrent or
     * double-submitted transition can't run the side-effect (posting, etc.) twice.
     */
    public function runTransition(Request $request, int|string $id, string $transition): RedirectResponse
    {
        $resolved = $this->resolveTransition($transition);
        abort_if($resolved === null, 404);
        abort_unless($this->canTransition($resolved), 403);

        $key = $this->service->query()->getModel()->getRouteKeyName();
        DB::transaction(function () use ($resolved, $id, $key) {
            $record = $this->service->query()->where($key, $id)->lockForUpdate()->firstOrFail();
            $current = (string) ($record->{$this->stateColumn} ?? '');

            abort_unless($resolved->appliesTo($current), 409); // wrong state — already transitioned, or invalid
            abort_unless($resolved->passesGuard($record), 422);

            // Atomic claim: advance the state with a conditional update keyed on the state we just read, so a
            // concurrent or double-submitted transition can't also win (correct even where lockForUpdate is a
            // no-op, e.g. SQLite). 0 rows affected = lost the race.
            $claimed = $this->service->query()->where($key, $id)->where($this->stateColumn, $current)
                ->update([$this->stateColumn => $resolved->toState()]) === 1;
            abort_unless($claimed, 409);

            // Sync the in-memory model to the claimed state, then run the side-effect; a throw rolls the whole
            // transaction back (the claim included), so a failed side-effect never leaves the record advanced.
            $record->{$this->stateColumn} = $resolved->toState();
            $resolved->run($record); // the side-effect (post movements, etc.)
            $record->save();         // persist any mutations the handler made on the record
        });

        return back()->with('success', __('admin-core::admin-core.states.transitioned'));
    }

    /** Refuse the write when the record sits in a locked state (a no-op unless $lockedStates is set). */
    protected function guardLocked(int|string $id): void
    {
        if ($this->lockedStates === []) {
            return;
        }

        if ($this->isLockedState($this->service->find($id))) {
            abort(403, __('admin-core::admin-core.states.locked'));
        }
    }

    /** Refuse the trash-path write (restore / force-delete) when the trashed record is in a locked state. */
    protected function guardLockedTrashed(int|string $id): void
    {
        if ($this->lockedStates === []) {
            return;
        }

        $key = $this->service->query()->getModel()->getRouteKeyName();
        $record = $this->service->trashedQuery()->where($key, $id)->first();
        if ($record !== null && $this->isLockedState($record)) {
            abort(403, __('admin-core::admin-core.states.locked'));
        }
    }

    /** Is the record currently in a locked state? */
    protected function isLockedState($record): bool
    {
        return in_array((string) ($record->{$this->stateColumn} ?? ''), $this->lockedStates, true);
    }

    /**
     * Exclude locked-state records from a bulk query (NULL state = not locked, matching isLockedState()).
     * A no-op unless $lockedStates is set.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    protected function excludeLocked($query)
    {
        if ($this->lockedStates !== []) {
            $query->where(fn ($q) => $q->whereNull($this->stateColumn)
                ->orWhereNotIn($this->stateColumn, $this->lockedStates));
        }

        return $query;
    }

    /** When the resource is a state machine, the state column moves only via transitions — never a form write. */
    protected function stripStateColumn(array $data): array
    {
        if ($this->transitions() !== []) {
            unset($data[$this->stateColumn]);
        }

        return $data;
    }
}
