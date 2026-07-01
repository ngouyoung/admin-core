# Changelog

All notable changes to `ngos/admin-core` are documented here.

## v2.78.0

**Live computed fields (`data-ac-compute`).** A form field whose value derives from sibling fields, updated as
the user types — a master-detail line's `line_total = qty × unit_price`, a `qty_base = qty × conversion_factor`.
The 3rd (final) gap from the SMPOS dogfood sweep: line-item forms wanted a live subtotal, not just a server-side
`computed` accessor on save.

### Added
- **`data-ac-compute="qty * unit_price"`** on any input (fills + posts the value) or element (shows it as text),
  recomputed live on input/change. Names resolve within the enclosing `[data-ac-repeater-row]` first (each line
  computes independently), else the `<form>`. `data-ac-compute-decimals="2"` rounds the result. A **safe
  arithmetic evaluator** (`+ - * / ( )`, numbers, field names — never `eval`; divide-by-zero → 0, malformed
  degrades to 0). Shipped as `compute.js` (imported in `app.js`), wired to `DOMContentLoaded` +
  `ac:repeater:added` so new repeater rows compute too.
- The generated hasMany **row partial** now ships a commented `line_total` example showing the pattern.

Publish the updated frontend assets (`compute.js` + `app.js`) and rebuild (`npm run build`).

**Singleton resources (`--singleton`).** Some admin screens edit **one** record, not a table — Settings, a
company profile, the current user's profile. `--singleton` scaffolds a model-backed, validated **edit form +
save** with no list/create/delete — filling the gap between `admin-core:make` (full CRUD) and
`admin-core:page` (model-less). The 2nd-ranked gap from the SMPOS dogfood sweep (`SettingController` /
`ProfileController` were hand-written).

### Added
- **`--singleton` flag** on `admin-core:make`. Generates a `SingletonController` (index = the edit form, update
  = save) + the update FormRequest + one edit view + the form partial. No StoreRequest, no
  list/show/create/thead views, no DataTable. Seeds **only the `edit` permission**. `--soft-deletes` /
  `--sortable` / `--api` are ignored with a notice; `--singleton --read-only` is rejected (different shapes).
- **`Ngos\AdminCore\Http\Controllers\SingletonController`** — a base with `index()` (render the edit form for
  the single record) + `update()` (validate + save, creating the row on first save). For a per-owner singleton
  override `recordScope()` (e.g. `['user_id' => auth()->id()]`) — the scope resolves the row AND is
  force-re-applied after the form fills, so a posted value can't repoint it to another owner. A `required`
  field-permission-denied field is force-merged before validation (mirrors the CRUD update).
- **`Route::crudSingleton($resource, $controller, $guard)`** — registers `GET /` (index) + `PUT /` (update),
  both gated by `edit-{resource}`. (Named crudSingleton to avoid Laravel's built-in `Route::singleton`.)

## v2.76.0

**Form-input transitions + pure actions.** A document action can now collect **validated input** and run a
**side-effect without moving state** — closing the single most recurring gap found dogfooding SMPOS (close-shift
needs a counted figure, checkout needs a payment method + amount, void needs a reason). Until now `runTransition`
locked/claimed/guarded/ran a handler atomically but never read a request body, so the instant an action needed
one field you fell off the skeleton into a bespoke route + controller + FormRequest + lock + idempotency you
already had for free.

### Added
- **`Transition::form($schema)`** — declare `field => rules` (or the rich `field => ['rules' => [...], 'type' =>
  ..., 'label' => ..., 'options' => [...]]`). The action's show-page button auto-renders a **modal**; the input
  is validated **before** the lock and the validated values reach the handler's **second argument**:
  `->handle(fn ($record, array $input) => …)`. A one-parameter handler still works (ignores the input).
- **`Transition::to(null)`** (or omitting `to()`) — a **pure action** that runs the guarded + validated + atomic
  handler **without** advancing a state column (a cash pay-in, a recompute). Kept idempotent by the form's
  one-time submit token (`_idempotency_key`), so a double-submit 409s — the same guard the create form uses.
  A state-moving transition keeps its atomic conditional state-claim.
- Field types inferred from the rules (`numeric`→number, `boolean`→checkbox, `date`→date, else text) or set
  explicitly; the modal re-opens with errors + the entered values after a failed validation.

### Changed
- `Transition::run()` now takes the validated input as a second argument (`run($record, array $input = [])`) —
  backward-compatible with existing one-arg handlers.

**Read-only resources (`--read-only`).** `admin-core:make Report --read-only` scaffolds a list + show + export
(with the DataTable filters/totals) but no create/edit/delete/import — a report stays inside the skeleton
instead of bypassing it. Completes the reporting story begun with list footer totals (v2.74.0), found
dogfooding SMPOS's four bespoke report controllers.

### Added
- **`--read-only` flag** on `admin-core:make`. Skips the FormRequests and the create/edit/form views; the
  generated controller has no `storeRequest`/`updateRequest`. Registers only the read routes
  (`Route::crud(..., readOnly: true)` → index/getData/select, plus show + export) — no
  create/store/edit/update/delete/ajaxDelete/bulkDelete/import/action/transition. Seeds **only the `list`
  permission**. `--soft-deletes`/`--sortable` (write features) are ignored with a notice. Combined with
  `--api`, the JSON API is read-only too (index + show, no store/update/destroy) — no full-CRUD API backed by
  the FormRequests that read-only doesn't generate.
- **`Route::crud($resource, $controller, $guard, readOnly: true)`** — a 4th argument that registers only the
  read routes.

### Changed
- The generated write buttons (create / import / bulk-delete / row edit + delete) are now **`Route::has()`-guarded**
  as well as permission-gated, so a button to a route that doesn't exist never renders (and never evaluates
  `route()` on a missing name). Backward-compatible — a normal resource has the routes, so nothing changes.

## v2.74.0

**List footer totals (column aggregates).** A list can now show a **totals row** — `revenue`, on-hand stock
value — computed server-side over the *filtered set* (all pages, honouring the active list filters), turning a
CRUD list into a report without dropping out of the skeleton. Closes the gap found dogfooding SMPOS, whose four
reports (`SalesReport`, `StockValuation`, `Reorder`, `ExpiringBatch`) each bypass admin-core and hand-roll a
query + custom blade precisely because they need a total a paginated list couldn't give them.

### Added
- **`WebController::listAggregates()`** — declare `['column' => 'sum'|'avg'|'min'|'max'|'count']`, or the long
  form `['column' => ['fn' => 'sum', 'money' => true, 'currency' => 'KHR']]` to format a money sum as exact
  Money. Computed over the filtered query (whitelisted fn + column name → never arbitrary SQL) and returned in
  the getData response as `acAggregates`. Default none → no footer.
- **Generator auto-totals money columns** — a resource with money columns gets `listAggregates()` summing each
  (formatted as Money) and the index passes `:aggregates` to `<x-admin-core::data-table>`. A per-record /
  multi-currency money column is skipped (mixed currencies can't sum to one amount).
- **`datatable.js`** builds the footer row before init and fills it from each AJAX response, so the total
  updates live as filters/pages change. The `admin-core::admin-core.list.total` label (en + km) sits in the
  leftmost cell.

### Notes
- The total reflects the structured list filters, **not** the free-text search box.
- Opt-in: a list shows a footer only when it declares `listAggregates()` (backward-compatible — existing lists
  are unchanged). Publish the updated `datatable.js` (`admin-core:doctor` flags it) to fill footers.

## v2.73.0

**Per-record money currency (multi-currency).** `total:money:@currency` lets one money column hold amounts in
different currencies row-by-row — a Purchase in USD next to one in KHR — reading each row's code from a sibling
column. Closes the last real-model gap found dogfooding SMPOS (`Purchase.currency`); the MoneyCast currency was
previously fixed per-column.

### Added
- **`price:money:@currency`** — the MoneyCast reads its currency from the named sibling column (enum/string)
  instead of a fixed code. Each row stores exact minor units for its own currency (USD `1500`, KHR `15000`) and
  reads/formats back in that currency; the edit form shows the record's symbol. Reads are always correct (the
  whole row is loaded); a `Money` of a different currency than the row's is refused, never reinterpreted.

### Notes
- A write parses the amount with the currency column's decimals, so **declare the currency column before the
  money column** (the generated form/rules then fill it first; CSV import re-orders via the rules too). The make
  command warns when it's declared after; if the currency is unknown at write time the configured default
  applies as a best-effort fallback.
- The `@column` reference is validated at generation — it must be a declared, **user-settable** enum/string
  column (a system `@` column is rejected, since it's never set from the form).
- A per-record column gets **no amount range filter** (a single bound can't honour each row's decimals — filter
  by the currency column instead), and **changing only the currency doesn't convert** a stored amount (re-enter it).

## v2.72.0

**`sequence` field — sequential document numbers.** `invoice_no:sequence:INV` auto-assigns `"INV-0001"`,
`"INV-0002"`, … completing the transactional-document story (number + line items + totals + state machine)
found dogfooding a real SMPOS sale/purchase (`sale_number` / `grn_number`).

### Added
- **`sequence` field type** — a system, **unique** string column assigned in the model's `creating` hook to the
  next number (bare → `"0001"`; `:INV` → `"INV-0001"`). Not fillable / not in the form / not validated; shown
  read-only. A number you set yourself is kept (`??=`).
- **`Ngos\AdminCore\Support\Sequence::next($key, $prefix, $pad, $reset)`** — concurrency-safe counter
  (`lockForUpdate` per `(key, period)` row, with a first-create-race retry); optional `reset` of `'year'`/`'month'`
  restarts the counter and stamps the period (`"INV-2026-0001"`).
- **`NumberSequence` model + `number_sequences` table** (package-shipped migration; run `php artisan migrate`).

## v2.71.0

**More list-filter types** — the advanced-filter bar (v2.69.0) gains `foreign`, number-range and `text`
filters, completing the filter set found dogfooding.

### Added
- **`foreign` filter** — a dropdown of the related rows (`Model::pluck('name','id')`), exact-matched on the FK.
- **`number` range filter** (min–max) — auto-generated for `money` and `decimal` columns; a **money** filter's
  typed major amount is converted to the stored **minor units** before comparing. `integer` is supported but
  not auto-generated (often an id/count — add it to `listFilters()` by hand).
- **`text` LIKE filter** — supported for string columns (added by hand; strings stay covered by the global
  search, so they aren't auto-generated to avoid clutter).
- `applyListFilters()` now handles `number` (≥/≤, money-aware) and `text` (`LIKE %…%`) alongside `select`/`date`;
  `<x-admin-core::list-filters>` renders the min/max and text controls (the front-end needed no change — the
  min/max parts reuse the date range's generic part mechanism).

## v2.70.0

**Saved views** — the advanced-filter bar (v2.69.0) gains a per-user "Views" dropdown: save the current
filters as a named view, re-apply one in a click, or delete it. The second half of the advanced/saved-filters
gap found dogfooding.

### Added
- **`SavedView` model + `saved_views` table** (package-shipped migration; `user_id` a plain indexed column,
  guard-agnostic) — a named filter set per (user, resource); saving the same name overwrites.
- **`Route::adminCoreSavedViews()`** + `SavedViewController` (index / store / destroy), every row **scoped to
  the current user** so one user can't list, overwrite or delete another's. `admin-core:install` wires the
  macro; existing installs add `Route::adminCoreSavedViews()` to their admin group and run `php artisan migrate`.
- **The `<x-admin-core::list-filters>` "Views" dropdown** (server-loaded via `index()`'s `$acSavedViews`) +
  `datatable.js` apply/save/delete. The dropdown degrades silently when the routes aren't wired.

## v2.69.0

**Advanced list filters** — a filter bar above the table: a dropdown per `enum`/`boolean` field and a date
range per `date`/`datetime` field, applied server-side. The first slice of the "advanced/saved filters" gap
found dogfooding (per-field filtering beyond the global search + the single status tab).

### Added
- **`listFilters()` + `applyListFilters()` on `WebController`** — a declarative, **whitelisted** per-column
  filter. The generator fills `listFilters()` from the fields (a `select` per enum/boolean, a `date` range per
  date/datetime); `applyListFilters()` applies `?filter[col]=value` (exact) and `?filter[col][from|to]=date`
  (range) before yajra's own search/sort/paging. A crafted `?filter[…]` on a non-declared column is ignored.
- **`<x-admin-core::list-filters>`** — the filter bar (in the generated `index` view, driven by `$acFilters`
  from `index()`); a Clear button resets it.
- **`datatable.js`** appends the bar's current values to every AJAX request (new `ajax.data` hook) and reloads
  the table on change. Backward-compatible: existing installs republish the JS + add the component to opt in.

## v2.68.0

**Composite unique constraints** — `--unique="order_id,product_id"` makes several columns unique *together*
(one product per order line, one SKU per branch), where the per-field `^` modifier only covers a single column.

### Added
- **`--unique` option** on `admin-core:make` (repeatable) — `admin-core:make OrderItem --unique="order_id,product_id"`:
  - a DB `$table->unique(['order_id', 'product_id'])` in the migration (the hard backstop), and
  - a FormRequest `Rule::unique` riding on the group's first column with a `->where()` for each of the others
    (ignoring self on update, `withoutTrashed()` on a soft-deletes resource) — so a duplicate combination fails
    with a clean validation message before it hits the database.
  - Validated at generation: each group needs ≥2 **distinct scalar** columns (rejects a repeated column —
    which would be invalid SQL — and text/json/translatable members). A group that includes a system (`@`) or
    write-once (`~`) column is DB-enforced only (its value isn't in the form to validate); the generator warns.

## v2.67.0

**`rollup` field type — a document total = sum of its line items.** `total:rollup:lines.line_total` sums a
child `hasMany` (money-aware), completing the master-detail document story found dogfooding a real SMPOS
sale/purchase: line items (`hasMany`) → per-line money totals (`money` + `computed`) → document total
(`rollup`).

### Added
- **`rollup` field type** — `admin-core:make Invoice --fields="…, lines:hasMany:invoice_lines, total:rollup:lines.line_total"`:
  - A read-only accessor `Rollup::sum($this->lines, 'line_total')` — derived, no column, not in the form,
    appended to array/JSON, shown read-only in the list + on show. The relation must be a declared `hasMany`.
  - **Money-aware**: money line totals sum to an exact `Money` (shown formatted, `$6.00`), plain numbers sum
    numerically, an empty document totals `0`. The summed child attribute can itself be a `computed`/`money`.
  - The rolled-up relation is **eager-loaded** in the list (no N+1).
- **`Ngos\AdminCore\Support\Rollup::sum()`** — the money-aware child aggregator. It fails loudly on
  inconsistent child data (a mix of Money and plain numbers, or money lines in different currencies) rather
  than returning a silently-wrong total.

### Notes
- A `hasMany` child is a separate resource the generator doesn't scaffold; with a rollup the list/show/API
  dereference it on every render, so `admin-core:make` now **warns** when the child model doesn't exist yet
  (generate it too).

## v2.66.0

**Money-aware computed expressions** — `total:computed:qty*price` now composes the `money` and `computed`
field types: when an operand is money, the compiler emits Money's exact methods instead of raw operators and
the result is a Money. This closes the headline gap from dogfooding (a line total `qty × unit_price` where
`unit_price` is money), found in a real SMPOS run.

### Added
- **Typed computed compiler.** A computed expression is now compiled by a small recursive-descent parser that
  tracks each operand's type (numeric vs money):
  - `money × scalar` (either order) → `$this->price?->multiply($this->qty)` → **Money**.
  - `money + money` → `?->add()`, `money - money` → `?->subtract()`, `money / scalar` → `?->divide()`.
  - numeric stays operators, with correct precedence + parentheses.
  - A money-typed computed is shown **formatted** (`$7.50`) in the list and on show, and is null-safe (a null
    money operand short-circuits via `?->`).
- **`Money::divide()`**; `Money::multiply()`/`divide()` now also accept a string (so a `decimal`-cast
  attribute like `'2.500'` can be a factor directly).

### Changed
- Nonsensical money arithmetic is rejected at generation with a clear message: `money * money`,
  `money ± number`, and dividing by money. Typos / non-numeric references / malformed formulas still fail loudly.

### Fixed
- **Bare `computed` stub generated invalid PHP** (a v2.65.0 regression): the stub body was `null; // …`, which
  broke the arrow function (`fn () => null; // …)`) so the model failed to parse. It's now a block comment.
- **`total:computed:0`** (and any expression whose trimmed value is the string `"0"`) was falsy-coerced to the
  stub instead of compiling to a numeric `0`.
- **Null operands no longer crash a computed total.** `Money::add/subtract/multiply/divide` now accept a null
  operand and yield null, so a computed total over a nullable money/numeric column (e.g. `subtotal + tax` with
  `tax` NULL) renders blank instead of throwing a `TypeError` on the list / show / API.

## v2.65.0

**`computed` field type** — a read-only value derived from other fields, not stored. `total:computed:qty*price`
generates an Eloquent accessor; bare `total:computed` scaffolds a stub you fill in. Shown read-only in the
list and on the show page, appended to the model's array/JSON — no column, never in the form, never validated.

### Added
- **`computed` field type** — `admin-core:make Order --fields="qty:integer, price:decimal, total:computed:qty*price"`:
  - Model: an `Attribute` accessor (`Attribute::get(fn () => $this->qty * $this->price)`) + `$appends` + the
    `use Attribute` import. A bare `total:computed` emits a TODO stub (with money / string / date examples).
  - The expression is a **safe arithmetic formula** (`+ - * / ( )`, numbers, other numeric field names): it's
    tokenised and grammar-checked at generation time, so a typo, a non-numeric/unknown reference, or a stray
    `//`/`--` fails loudly — user input can never become arbitrary or broken generated PHP.
  - Not a column: excluded from the migration, `$fillable`, validation, the form, the factory, export and API
    filters/sorts; shown read-only in the list (`addColumn`, not orderable/searchable) and on the show page,
    and included in the API resource.

### Notes
- Money / string / date math (and anything non-arithmetic) goes in a bare `total:computed` stub — the
  expression form is numeric-only by design.
- Add computed fields at `make` time; `admin-core:field` defers them to the full generator (it can't
  surgically inject the accessor + `$appends`).
- A computed value is appended to every serialization, so make sure its source columns are loaded — a partial
  `select()` that omits them reads a numeric formula as `0`.

## v2.64.0

**`money` field type** — store money exactly. A `price:money` field keeps the amount in **minor units**
(cents) in a `bigInteger` column and casts it to a `Money` value object, so amounts and sums stay exact —
no binary-float drift (`0.1 + 0.2` is `0.30`, not `0.30000000000000004`). Currency-aware: **Khmer Riel (KHR)
is 0-decimal** (៛15,000 → `15000`), USD is 2-decimal ($15.00 → `1500`), all driven by config.

### Added
- **`money` field type** — `admin-core:make Product --fields="name:string, price:money, cost:money:KHR"`:
  - Migration: `bigInteger` (minor units; holds large/negative amounts exactly).
  - Model cast: `MoneyCast` → reading gives a `Money` object, writing accepts a major amount (`'15.00'`) or a
    `Money` and stores the exact minor-unit integer. Pin a column's currency with `price:money:KHR`.
  - Form: a `<x-admin-core::money-input>` — a number control prefixed with the currency symbol, `step` from
    the currency's decimals (USD `0.01`, KHR `1`).
  - List + show: rendered via `Money::format()` ("$15.00" / "៛15,000"); ordering uses the raw minor column.
  - Validation: `numeric`. API: sortable. CSV export writes the plain `major()` value so a round-tripped
    import re-parses exactly.
- **`Ngos\AdminCore\Support\Money`** — an immutable value object: `fromMinor()` / `fromMajor()`, `minor()`,
  `major()` (exact integer→string, no float), `format()`, `add()` / `subtract()` / `multiply()` (exact,
  same-currency or it throws), `isZero()` / `isNegative()`, and `toArray()` / `jsonSerialize()`
  (`{minor, major, currency, formatted}`) for APIs.
- **`config('admin-core.money')`** — `currency` (default, via `ADMIN_CORE_CURRENCY`) plus a `currencies` map
  of `decimals` / `symbol` / `position` / `thousands` / `decimal` per currency (USD, KHR, EUR, GBP, JPY, THB,
  VND ship by default; an unlisted currency falls back to its code as symbol, 2 decimals).

## v2.63.0

**Document state machine** — give a resource a lifecycle (draft → confirmed → posted …) with gated, atomic
transitions and read-only locking. The first piece of "transactional document" support (the #1 gap from
dogfooding an inventory ERP), built on the Action / Approval infrastructure.

### Added
- **`Transition` + `transitions()`** — declare a document's lifecycle on the controller:
  ```php
  protected array $lockedStates = ['posted', 'cancelled'];

  protected function transitions(): array
  {
      return [
          Transition::make('confirm')->from('draft')->to('confirmed'),
          Transition::make('post')->from('confirmed')->to('posted')->confirm()
              ->handle(fn ($record) => $record->postToStock()),   // the atomic side-effect
          Transition::make('cancel')->fromAny()->to('cancelled')->color('danger')->confirm(),
      ];
  }
  ```
  Transition buttons render on the record's show page (`<x-admin-core::transitions>`). Fluent:
  `->from(…)` / `->fromAny()`, `->to()`, `->permission()` / `->withoutPermission()` (default `{key}-{resource}`),
  `->confirm()`, `->guard(fn ($r) => …)` (veto), `->handle(fn ($r) => …)` (side-effect), `->color()` / `->icon()`.
- **`$lockedStates`** — a record in one of these states is read-only: edit, delete, bulk-delete, restore and
  force-delete are all refused.

### Security
- A transition is **atomic** — the state advances via a conditional claim (`… where {state} = <current>`) inside
  a row-locked transaction, so a concurrent or double-submitted transition can't run the side-effect twice
  (correct even where `lockForUpdate` is a no-op). A failing side-effect rolls the whole transition back.
- The transition permission is enforced **server-side**, and once `transitions()` is declared the **state column
  is stripped from the ordinary create/edit write** — it can't be set directly to skip a transition (and its
  side-effect). The lock is consistent across every destructive path (single + bulk, trash included).

### i18n / Generator
- A `states.*` block (en + km). New resources are generated with a commented `transitions()` example and the
  show view includes the transitions component (a no-op until you declare a lifecycle).

### Upgrade
Backward-compatible. Existing resources are unchanged — the defaults (`$lockedStates = []`, `transitions() = []`)
are a complete no-op. The `transition/{id}/{transition}` route is added by the `crud` macro on package update.

## v2.62.4

Docs only — a hand-held getting-started tutorial (in response to "I don't understand how to use it"). No code
change.

### Docs
- **`TUTORIAL.md`** — a zero-to-working walkthrough: install → log in → generate **Categories** → generate
  **Products** (a relation + image + enum + boolean) → roles & permissions → a custom action, with every step
  explained and every command verified. Linked prominently from the README top + Contents, so a newcomer has a
  guided path before the reference.

## v2.62.3

Docs only — the README caught up with the v2.56–v2.62 feature arc. No code change.

### Docs
- New README sections: **custom table actions** (`resourceActions()`), **field-level permissions**
  (`fieldPermissions()`), the **approval workflow** (`->requiresApproval()` + the inbox), the **media library**
  (`media` / `gallery` fields + `HasMedia`), and **dashboard widgets** — plus an updated Contents/TOC.

## v2.62.2

Quick fixes + a security policy.

### Fixed
- **`--api` N+1**: a generated API resource with a `media`/`gallery` field now eager-loads the `media`
  relation, so a list response no longer fires one query per row. The web list is unaffected (it doesn't show
  media as a column, so it isn't eager-loaded there).
- **`admin-core:install`** no longer reports "updated routes/web.php (media endpoints)" when the anchor isn't
  present — a no-op is no longer logged/flagged as a change.

### Added
- **`SECURITY.md`** — how to report a vulnerability privately.

### Upgrade
Backward-compatible. To pick up the `'media'` eager-load on an **existing** API resource, add `'media'` to its
`$with` (or re-generate); new resources get it automatically.

## v2.62.1

Internal only — a test suite + CI for the shipped front-end JS. The installed code is identical to v2.62.0
(no migration, config, or stub change); nothing to do on upgrade.

### Internal
- **JS tests (Vitest + jsdom)** covering the shipped stubs' critical behavior: HTML escaping (`acEsc` and the
  media picker's `esc`), custom-action dispatch (`acRunAction` — ids payload, confirm gating, empty no-op,
  error toast), bulk-button injection (escaping, placement, idempotency), and an **end-to-end stored-DOM-XSS
  regression** for the media picker (a malicious filename can't break out of the markup).
- **CI** now runs the JS suite (Node 22) alongside the PHP tests + Larastan.

## v2.62.0

**Action approval workflow** — a sensitive table action can require sign-off: a staff member who may request it
but not approve it files a pending request; an approver runs it from an inbox. Builds on v2.61's table actions.

### Added
- **`Action::make('…')->requiresApproval()`** — when run by a user who has the run permission but **not**
  `approve-{key}-{resource}`, the action is held as a pending request instead of executing. A user who *can*
  approve runs it directly. Per-action, so only the actions you mark need sign-off:
  ```php
  Action::make('mark-paid')->requiresApproval()
      ->handle(fn ($records) => $records->each->update(['status' => 'paid']));
  ```
- **Approvals inbox** — `Route::adminCoreApprovals()` (wired by `admin-core:install`) adds an inbox at
  `admin.approvals.index` to **approve** (re-runs the original action over the captured rows) or **reject**
  (with a reason). A "System → Approvals" menu entry (gated by `list-approval`).
- **`Approval` model + `approvals` migration** — polymorphic requester/approver (any user model / guard),
  the action + captured row ids (payload), status, notes, decision timestamp.
- **Notifications** — approvers are notified of a new request, the requester of the decision (reuses the
  in-app notification system; best-effort when the host user model isn't permissioned/notifiable).

### Security
- Approving is enforced **per action** (`approve-{key}-{resource}`) in the controller, independent of the
  inbox's `list-approval` route gate — a user who can see the inbox still can't decide an action they lack
  the approve permission for (HTTP 403).
- Approve/reject **atomically claim** the request (a conditional `pending →` decision update), so a concurrent
  or double-submitted approve can't run a non-idempotent action twice.
- On approval the action re-resolves its rows through the resource query (scopes/soft-deletes apply) and only
  runs via a verified `WebController` handler.

### Permissions
- New `list-approval` (inbox access) in the AccessSeeder. Add an `approve-{action}-{resource}` permission for
  each action you mark `->requiresApproval()` (e.g. `approve-mark-paid-order`) and grant it to your approver
  role — keep it from the role that only requests.

### i18n
- An `approvals.*` block + `toast.action_pending` (en + km).

### Notes
- Decisions are global per action (not tenant-partitioned), but execution re-scopes to the approver, so an
  approver can only ever act on rows they can see.
- The approve permission is always `approve-{key}-{resource}`; an action's `->permission()` override only
  changes the permission to *request* it.

### Upgrade
Backward-compatible. Existing actions are unchanged; actions only enter the workflow when you add
`->requiresApproval()`. Run `php artisan migrate` for the `approvals` table and
`php artisan admin-core:install` to wire the inbox route. An install that never wires it simply hides the
menu entry (the sidebar drops unregistered routes) — nothing breaks.

## v2.61.0

Declarative **table actions** (bulk + per-row, permission-gated) and **field-level permissions** — two
batteries-included primitives every admin eventually needs, generic for any resource.

### Added
- **`Action` + `resourceActions()`** — declare a table action once and get a bulk-toolbar button, a per-row
  menu item, the route, the permission gate, the confirm dialog and the success toast:
  ```php
  protected function resourceActions(): array
  {
      return [
          Action::make('mark-paid')->label('Mark as paid')->icon('bi bi-cash')->color('success')->confirm()
              ->handle(fn ($records) => $records->each->update(['status' => 'paid'])),
      ];
  }
  ```
  Fluent: `->permission('…')` (defaults to `{key}-{resource}`), `->withoutPermission()`, `->onlyBulk()`,
  `->onlyOnRow()`, `->success('…')`. The handler receives the affected models, already resolved **through the
  resource query** (global scopes / soft-deletes / tenancy apply — you can only act on rows you can see).
- **`fieldPermissions()`** — lock sensitive fields: `['status' => 'change-status-order']`. A user without the
  permission can neither see the field (it's disabled in the form via the new `<x-admin-core::field-guard>`)
  nor write it (stripped server-side on store/update). Covers direct fillable columns.
- **`Route::crud()` gains an `action/{action}` route** automatically — every resource (new and existing) gets
  table actions on upgrade, no regeneration needed. New resources are generated with commented examples.

### Security
- The action permission is enforced **server-side in `runAction()`** (HTTP 403), independent of the UI — hiding
  a button is cosmetic; the gate is the guard. A guest / unpermitted user can't run an action by POSTing to it.
- Field-level locks are enforced on the **write** (`stripDeniedFields`), so a hand-crafted POST can't set a
  locked field; on update the stored value is merged past validation so a locked **required** field still saves.
- `bulkIds()` now drops non-scalar id elements (a nested-array element previously could reach the query) —
  hardens every bulk endpoint. The `{action}` route key is constrained to `[A-Za-z0-9_-]+`.

### i18n
- New strings `confirm.run_action` and `toast.action_done` (en + km).

### Tests
- The `Action` value object; `runAction` (scope, 404/403 gate, ungated, custom message); field strip on
  create/update incl. the locked-required-field and tampered-value cases; the bulk/row config the front-end
  reads; the `field-guard` component; the toolbar-less header.

### Upgrade
Backward-compatible. Existing controllers (no `resourceActions()` / `fieldPermissions()`) are unchanged. To
add actions to an **existing** resource, declare `resourceActions()`, set `$this->resource`, add the permission
to your seeder, and pass `:actions="$acActions ?? []"` to its `<x-admin-core::data-table>` (new resources get
this automatically). Publish the updated JS with `php artisan admin-core:doctor --fix && npm run build`.

## v2.60.0

Polymorphic **media attachments** — any model can own multiple library files per named collection (with reuse),
plus `media`/`gallery` generator field types that wire it end-to-end.

### Added
- **`HasMedia` trait** — `use HasMedia` gives a model `media()`, `mediaIn('gallery')`, `firstMediaUrl('gallery')`,
  `attachMedia()`, and `syncMedia([ids], 'gallery')`. Files attach via a polymorphic `mediables` pivot
  (collection + order), so one library file can be reused across records and a record can hold many files.
- **`<x-admin-core::media-collection>` form control** + a shared picker modal: browse the library, upload, and
  drag-reorder the attached files; the field submits the ordered media ids.
- **`media` (single) / `gallery` (multiple) field types** — `admin-core:make Product --fields="cover:media,
  photos:gallery"` wires the trait, validation, the picker control, and `syncMedia` in the service
  automatically (no column — a relation). Shown in `--list-fields`.
- **`media.list`** endpoint (the picker's library browser).

### Security
- The picker JS escapes filenames (a media name is a user-supplied filename) to prevent a stored DOM XSS, and
  `MediaLibrary` strips HTML-dangerous characters from stored names.
- Deleting a library file is **refused while it's still attached** to a record (HTTP 409) — no silent removal
  from galleries that still reference it.

### CI
- The workflow retries `composer update` (3×, 15s backoff) and caches Composer, so a transient Packagist
  502/504 self-heals instead of failing the build.

### Tests
- The trait (attach / sync / order, **collection isolation**, the delete hook), the in-use delete guard, the
  picker control + modal, the `media.list` endpoint, and an end-to-end generated `gallery` resource (trait, no
  column, picker, `syncMedia`).

### Upgrade
Backward-compatible. The `mediables` table is created on the next `php artisan migrate`. The picker JS +
`media.list` route arrive via `php artisan admin-core:install` + `admin-core:doctor --fix && npm run build`.

## v2.59.0

A **media library** — browse, drag-drop upload, search, and manage every uploaded file in one place
(`/admin/media`), built on the existing WebP / disk / CDN upload pipeline.

### Added
- **Media library screen** — a paginated grid of every uploaded file: drag-drop (or click) upload, search by
  name, filter by collection, copy a file's URL, delete. Images show a thumbnail; other files (PDF, doc, csv,
  zip…) show a type icon. Reached from the sidebar **Media** link.
- **`media_items` registry + `MediaLibrary` service** — `store()` / `delete()` / `query()` / `collections()`,
  riding `Support\Media` (WebP compression, disk, and CDN configured once). Images record width/height.
- **`Route::adminCoreMedia()`** macro (index + upload + delete), permission-gated by `manage-media` when
  permissions are enabled; wired into the admin group by `admin-core:install`.
- Config: `admin-core.uploads.allowed_mimes` (the upload allowlist) + `uploads.max_kb`.

### Security
- The uploader enforces an **allowlist** (`uploads.allowed_mimes`, default images + common docs) and rejects
  executable / markup uploads (php, phtml, svg, html…) that would otherwise be served from the public disk.

### Tests
- The service (store + register, delete + file removal, search / collection filter) and the endpoints
  (multi-file upload, a dangerous-type upload rejected, delete).

### Upgrade
Backward-compatible. The `media_items` table is created on the next `php artisan migrate`. The sidebar link +
upload/delete routes are gated by a new `manage-media` permission: super-admins have it via the super-role; to
grant it to other admins, **re-run your AccessSeeder** (or add the `manage-media` permission) — until then a
non-super user gets a 403 on `/admin/media` (no hard error). The `media.js` behaviour arrives via
`admin-core:doctor --fix && npm run build`. A media **picker** (reuse library files inside image/file form
fields) and polymorphic `HasMedia` model attachments are planned next.

## v2.58.0

Generated **create forms are now duplicate-submit-proof** out of the box — a double-click, a frantic re-tap,
or a browser retry can no longer create two records.

### Added
- **Idempotent create submits.** Every generated create form carries a one-time
  `<x-admin-core::idempotency-key />` token, and `WebController::store()` claims it atomically (`cache()->add`)
  before creating: a repeated POST with the same token returns success **without** creating a duplicate. No
  per-resource column or migration — the token lives briefly in the cache. The claim is released if the create
  fails, so a genuine retry still works; validation runs first, so a validation error never wastes the token.
- **`<x-admin-core::idempotency-key />`** — drop it into any custom create form (after `@csrf`) for the same
  protection. The token survives a validation-error redisplay (via `old()`), so the corrected resubmit dedupes
  against the right token.
- A client-side **disable-on-submit** (`forms.js`) on idempotent forms as a first line of defence.

### Config
- `admin-core.forms.idempotency` (default `true`) toggles the guard; `forms.idempotency_ttl` (minutes) is how
  long a token is remembered. Best-effort on the `file` cache driver (no atomic `add`) — use
  redis/memcached/database for strict dedup under true concurrency.

### Tests
- A duplicate submit creates one record (not two); no token → unguarded (backward-compatible); a failed create
  releases the token so the retry succeeds; the generator emits the token in the create view.

### Upgrade
Backward-compatible. Existing create forms (no token) keep their current behaviour; re-generate a resource
(`admin-core:make <Name> --force`) or add `<x-admin-core::idempotency-key />` after `@csrf` to opt a form in.
The disable-on-submit JS arrives via `admin-core:doctor --fix && npm run build`.

## v2.57.0

A generic, config-driven **dashboard widget framework** — drop `<x-admin-core::dashboard />` into your
dashboard view and declare widgets in `config('admin-core.dashboard.widgets')`. The package lays them out;
your app supplies the data, so it's entirely app-agnostic (a POS, a CRM, a blog — all just config + a callback).

### Added
- **Widgets** — `StatWidget` (a KPI with an automatic trend arrow vs the previous period + a drill-down link),
  `ChartWidget` (ApexCharts), `ListWidget` (recent/top rows), or the base `Widget` for a custom partial. Declare
  each as a class-string **or** an inline config array (closures receive a `DashboardContext`).
- **`<x-admin-core::dashboard />`** — renders the widgets in a responsive grid, permission-filtered, in each
  user's saved arrangement.
- **Date-range toolbar** (Today / 7d / 30d / This month / All time, or `?from=&to=`) every widget respects via
  `DashboardContext::scope()` / `scopePrevious()` — the previous window (disjoint, no double-count) drives the
  trend deltas.
- **Lazy-load + auto-refresh** — `lazy()` widgets render a skeleton then load from the
  `Route::adminCoreDashboard()` endpoint; `refreshSeconds()` widgets re-poll. Wired by `admin-core:install`;
  degrades to inline render where the endpoint isn't present.
- **Server-side caching** — `cacheSeconds()` caches a widget's data per (key + range + user).
- **Per-user layouts** — a Customize mode (drag to reorder, hide widgets) saved per user in a package-owned
  `dashboard_layouts` table (auto-migrated; saved keys are validated + size-capped).
- **Generators** — `admin-core:make-widget {Name} [--type=stat|chart|list]` scaffolds a widget class, and
  `admin-core:make … --widget` auto-scaffolds a count widget for a resource.

### Tests
- Widget resolution (class + config), trends, permission filtering, date-range presets + disjoint previous
  windows, custom-range cache isolation, duplicate-key dedupe, the lazy endpoint, per-user arrangement
  (order/hidden + append-new), and the layout save endpoint (filtered to real keys + size-capped).

### Upgrade
Backward-compatible. Existing dashboards are untouched — the framework is opt-in (add
`<x-admin-core::dashboard />` to a view and declare widgets). For lazy-load/charts/customize on an existing
install, run `php artisan admin-core:install` (adds the route) and `admin-core:doctor --fix && npm run build`
(publishes `dashboard.js`); the `dashboard_layouts` table is created on the next `php artisan migrate`.

## v2.56.0

Three follow-ups that finish the searchable-select story and make generated menus translatable.

### Changed
- **`belongsToMany` fields now generate a searchable, paginated remote multi-select** — closing the gap left
  in v2.54.0. `admin-core:make` emits `<x-admin-core::select source="tags" … multiple>` that pre-renders only
  the currently-attached rows and loads the rest on demand from the related resource's `select` endpoint. **No
  more eager-loading the entire related table** into `<option>`s (the same change `foreign` got in v2.54.0).
  Validation, the pivot `->sync()`, and the export columns are unchanged.
- **The unsearched remote select opens on the indexed primary key, not the label.** `WebController::select()`
  sorted *every* row by `name` before taking the first page — a filesort over the whole table on a plain open
  (painful at tens of thousands of rows). It now orders an un-narrowed open by the primary key (an index range
  scan + `LIMIT`) and only sorts by the human label once a search term or cascade filter has narrowed the set.
  The only visible change: the pre-typing dropdown page is in id order instead of alphabetical; search and
  cascade-filtered results stay alphabetical.

### Added
- **Generated menu labels are registered for translation.** `admin-core:make` now seeds the new resource's
  sidebar label into each configured non-default locale's `lang/<locale>.json` (e.g. `lang/km.json`), with the
  English text as a safe placeholder, so `__($label)` resolves and a translator (or `admin-core:translate`)
  can fill it in. It only ever *appends* to a locale file the host already ships — never creates one, never
  overwrites an existing value, skips the source locale, and is a no-op when no JSON lang files are present.

### Tests
- The generator emits a `multiple` remote select with `source="…"` for a belongsToMany field and no longer
  eager-loads the whole related table; the select endpoint orders an empty open by primary key but by label
  under a term; `admin-core:make` seeds the menu label into a non-default locale JSON while leaving the source
  locale untouched.

### Upgrade
Backward-compatible. **Existing** generated forms keep their static multi-selects until regenerated — re-run
`admin-core:make <Name> --force` to switch a belongsToMany field to the searchable picker. The select-ordering
and menu-label changes take effect immediately and need no action. (One known parity limitation, shared with
`foreign`: after a validation error a just-added-but-unsaved selection stays in the submitted data but its
chip isn't re-rendered until you search again.)

## v2.55.0

Dependent (cascading) selects — Province → Commune → Village, auto-wired by the generator. A foreign-key
select can now narrow itself by a parent field: pick a Province and the Commune dropdown shows only that
province's communes; pick a Commune and the Village dropdown narrows in turn, for as many levels as the chain
has.

### Added
- **`:depends-on` on `<x-admin-core::select>`** — a `[column => '#selector']` map. The child sends the
  parent's value as a `filter` to its `select` endpoint and reloads when the parent changes; clearing
  cascades down the chain (new Province → Commune clears → Village clears).
- **`$selectFilters` on the controller** — the allowlist of columns a child may filter the remote source by.
  `select()` applies `filter[col]=val` only for these, parameter-bound — the client can't filter by an
  arbitrary column (same safety model as `$selectSearch`).
- **Generator auto-wiring** — `admin-core:make` sets `$selectFilters` to the resource's foreign keys, and a
  generated form **infers `:depends-on`** for a foreign field when its related table carries another of the
  form's foreign keys (e.g. `commune_id` → the `communes` table has `province_id` → depends on `province_id`).
  Schema-probed, so the related tables must be migrated; degrades to a plain remote select otherwise.

### Tests
- The remote source filters by an allowlisted column and ignores others; the component emits
  `data-ac-depends`; the generator infers `:depends-on` from the related schema and sets `$selectFilters`.

### Usage
```blade
<x-admin-core::select name="province_id" source="provinces" />
<x-admin-core::select name="commune_id"  source="communes"  :depends-on="['province_id' => '#province_id']" />
<x-admin-core::select name="village_id"  source="villages"  :depends-on="['commune_id' => '#commune_id']" />
```
(emitted automatically when you scaffold a resource whose form holds the whole chain.)

## v2.54.0

Generated foreign-key form fields are now **searchable + paginated** out of the box.

Until now `admin-core:make` emitted a **static** `<select>` for every `foreign` field — it eager-loaded the
*entire* related table into the form (`Category::orderBy('id')->get()`) and rendered every row as an
`<option>`. Fine for a handful of rows; with thousands it bloats the page, and with tens of thousands it's
effectively unusable (no server pagination, only client-side search over a giant DOM). The remote Select2
added in v2.52.0 existed, but you had to wire `:ajax-url` by hand — generated forms never used it.

### Changed
- **`admin-core:make` generates a remote (ajax) select for `foreign` fields.** It emits
  `<x-admin-core::select source="categories" …>` with only the currently-selected option pre-rendered (so an
  edit form still shows it) — the rest load on demand from the resource's `select` endpoint. **No more
  eager-loading the whole related table.** (belongsToMany multi-selects stay static for now.)

### Added
- **`:source` on `<x-admin-core::select>`** — point it at a resource's route base and the component resolves
  the `…select` endpoint **dynamically** from `config('admin-core.route.name_prefix')`, so it's
  **portal/prefix-safe** (no hardcoded `admin.`). Guarded by `Route::has`: if that resource has no `select`
  route, it **falls back to a plain static select** and never errors. An explicit `:ajax-url` still wins.

### Tests
- `:source` resolves to the prefixed `…select` route (ajax mode) and falls back to a static select when the
  route is absent; FieldSet emits `source="…"` + a localized preselected option for foreign fields.

### Upgrade
Backward-compatible. **Existing** generated forms keep their static selects until regenerated — re-run
`admin-core:make <Name> --force`, or just add `source="…"` to a `<x-admin-core::select>`, to switch a form to
the searchable/paginated picker. (Relies on the per-resource `select` endpoint shipped in v2.52.0.)

## v2.53.0

Data-driven DataTables — list pages no longer carry per-resource init JS. Until now every generated list page
shipped ~70 lines of near-identical DataTable init in its own `partials/scripts.blade.php` (the `.DataTable()`
call, select-all, bulk-delete and single-delete handlers); only the column list varied, the rest was
copy-paste, and a fix never reached already-generated tables. This moves all of it into one shared module
driven by a config the `data-table` component emits.

### Added
- **`datatable.js` frontend module** (`stubs/frontend/resources/js/datatable.js`, wired into `app.js`) —
  auto-initialises every `<table data-ac-datatable='{…}'>`: builds the DataTable from the config and wires
  select-all + bulk-delete + single-delete once via event delegation. The table still loads its rows
  **server-side from `getData`** (yajra) — only the boilerplate moved. Backward-compatible: it acts only on
  tables carrying `data-ac-datatable`, so a resource still using its own `partials/scripts.blade.php` is
  untouched.
- **`<x-admin-core::data-table>` data-driven mode** — new `:columns`, `:ajax`, `:bulk-delete`, `:order`
  props. With `:columns` it emits a `data-ac-datatable` config (page length, the column list, the bulk url,
  and the locale-aware confirm/toast strings via `__()`); without them it renders exactly as before.
- **`FieldSet::columnsConfig()`** — the same column list as `columnsJs()`, as a PHP array literal for the
  component.

### Changed
- **The generator emits the slim, data-driven list page.** `admin-core:make` no longer writes a
  `partials/scripts.blade.php`; `index.blade.php` passes `:columns` / `:ajax` / `:bulk-delete` to the
  data-table component (still server-driven via `getData`). `admin-core:field` inserts new columns into that
  `:columns` array (older resources with a `scripts.blade.php` keep being patched there).

### Upgrade (existing installs)
Backward-compatible — existing resources keep their `partials/scripts.blade.php` and work unchanged. To use
the shared module, publish the new `datatable.js`: add `import './datatable';` to `resources/js/app.js`, copy
`datatable.js` from the stubs, and rebuild assets (`admin-core:doctor` flags the `app.js` drift). You can then
migrate a list page by passing `:columns` to `<x-admin-core::data-table>` and deleting its
`partials/scripts.blade.php`.

### Tests
- The data-table component emits a valid `data-ac-datatable` config (checkbox type, ajax, bulk, i18n) and
  omits it without `:columns`; the generator produces the data-driven index and no `scripts.blade.php`;
  `admin-core:field` patches the `:columns` array.

## v2.52.1

The sidebar menu is now translatable.

### Changed
- **Sidebar menu labels render through `__()`.** Section headers, group (treeview) labels and leaf labels
  now pass through the translator, so a `lang/<locale>.json` entry renders the menu in that language. The
  stored (English) text is the lookup key, which means it works for **both** config- and database-driven
  menus without a schema change. Backward-compatible: a label with no matching translation falls through to
  itself, so existing menus are unaffected. Switch with the app locale (e.g. `APP_LOCALE`) or a per-request
  locale.

### Tests
- The sidebar renders translated headers, group labels and leaf labels at a non-default locale, and
  untranslated text falls through unchanged.

## v2.52.0

Searchable, paginated dropdowns for big lists. A static `<select>` ships every option in the HTML — fine for
a handful of categories, slow and heavy for thousands of products. This adds an opt-in **remote (ajax)
Select2** that searches and pages server-side, available to every resource without per-model wiring.

### Added
- **`WebController::select()` — a Select2 remote source on every resource.** Returns one page of
  `{results: [{id, text}], pagination: {more}}`, filtered by the typed `term` and ordered by the label.
  It's auto-registered as the `select` route inside the `crud` macro, under the **same `list` permission as
  `getData`**, so each resource gets `…{resource}.select` for free. The searchable columns and label are
  chosen **server-side** via `$selectSearch` / `$selectLabel` (default `['name']`) — the client can never
  point it at an arbitrary model or column, and it rides the resource's own `query()` so scopes / soft
  deletes still apply. Page size is `config('admin-core.select.per_page', 20)`.
- **`<x-admin-core::select :ajax-url="…">` — remote mode for the select component.** Point it at a
  resource's `select` route and it renders only the currently-selected option (so an edit form shows it),
  loading the rest on search. Emits `.admin-core-select-ajax` + `data-ajax-url` instead of the static
  `.admin-core-select` class, so the two never collide.
- **`select-ajax.js` frontend module** (wired into the `app.js` stub) — binds every remote select on load
  and **re-binds rows the repeater adds** (`ac:repeater:added`), so it works in master-detail forms too.

### Tests
- The remote source returns `{id, text}` filtered by the term, and paginates (`more` flips false on the last
  page) — in the package suite against the `Widget` fixture.

### Upgrade (existing installs)
Backward-compatible — nothing breaks. To use the new ajax mode in an app installed before v2.52.0, add
`import './select-ajax';` to `resources/js/app.js` and copy the new `select-ajax.js` module from the stubs,
then rebuild assets. (`admin-core:doctor` flags the `app.js` stub drift.) The `select` endpoint and the
component's `:ajax-url` prop work immediately on upgrade; only the JS needs publishing.

## v2.51.17

A small correctness batch — a decimal guard plus two multi-portal fixes surfaced by a focused audit of the
API / translation / portal subsystems (the audit otherwise confirmed the JSON API is sound: whitelisted
filter/sort, clamped `per_page`, `$fillable`-guarded mass-assignment, passwords excluded from Resources).

### Added
- **`DecimalPrecision` validation rule** (`Ngos\AdminCore\Rules`) — rejects a number that wouldn't fit its
  `decimal(p, s)` column (too many digits before/after the point) instead of letting the database silently
  truncate it. The generator emits `new DecimalPrecision($p, $s)` for every `decimal:p|s` field; the rule
  lives in `src/` so a fix reaches every install (not a frozen stub).

### Fixed
- **`AuthorizeApiPermission` honours a portal's own super role.** It resolved the super-role bypass against
  the default `super_role` only; on a portal guard it now reads
  `admin-core.permission.guards.<guard>.super_role` first — matching the web side, so a portal super-admin
  isn't wrongly 403'd on API routes.
- **`LogsActivity` attributes a change to the guard that actually made it.** It read the default (`web`)
  guard's user, so a multi-portal action was logged with the wrong causer (or none). It now resolves the
  causer from whichever configured guard is authenticated. No change for single-guard apps.

### Tests
- DecimalPrecision (fit / over-precision / over-scale / scientific / non-numeric passthrough); the
  guard-specific super-role bypass; multi-guard causer attribution.

## v2.51.16

The root-cause fix for **stub drift** — the recurring problem where a published frontend file (JS behaviour,
theme SCSS, layout Blade) freezes at install time and never receives a later package fix.

### Added
- **`admin-core:doctor`** — compares an app's published admin-core frontend assets against the current
  package version and reports what has **drifted** or gone **missing**, so a fix doesn't sit silently
  unapplied. Behaviour files (`.js`) — the ones that usually carry fixes — are flagged distinctly from
  customisable theme/layout files.
  - report-only by default (exits non-zero when anything drifted — CI-catchable);
  - `--diff` prints a unified diff per drifted file;
  - `--fix` updates the drifted/missing files to the package version (refuses non-interactively without
    `--force`; review with `git diff` afterwards, since your own theme/layout edits live in these files too).

### Tests
- In-sync vs drifted vs missing detection; `--fix` restore; the non-interactive `--force` safety guard.

## v2.51.15

DX features that cut hand-rolled boilerplate (driven by real app usage).

### Added
- **`<x-admin-core::date-input>` component** — a date / datetime field pre-wired to the bundled
  AirDatepicker (`.js-datepicker` + `data-adp`, re-inits on repeater rows). Formats a Carbon value and
  echoes a re-submitted string as-is (so a bad date can't throw on re-render). `mode="datetime"` for
  date+time. The generator now emits it for `date:` / `datetime:` fields instead of a hand-built input.
- **`BaseService::syncHasMany()`** — the master-detail reconcile for repeater-backed forms (a parent + its
  line items): update rows by `id`, create new ones, delete the rest; `null` leaves the relation untouched.
  Pass an `$attributes` callback to whitelist/derive per-row columns (return `null` to skip a row). Generated
  resources with a `hasMany` field now call it instead of emitting their own copy.
- **`admin-core:page --report`** — scaffolds a data-driven read-only report (count badge + empty-state +
  table) instead of a blank page, reusing the same route / permission / sidebar machinery. Fill in the query
  and columns. Removes the boilerplate every report screen repeats.

### Tests
- date-input render (Carbon formatting, datetime mode, raw-string passthrough); `syncHasMany`
  create/update/delete/null/empty/transform; the `--report` scaffold output.

## v2.51.14

Correctness + hardening from a focused package self-audit (findings verified against ground truth).

### Fixed
- **The generated `unique` rule excludes soft-deleted rows** on a `--soft-deletes` resource
  (`->withoutTrashed()`), so a value freed by deleting a row can be reused — previously a soft-deleted row
  silently blocked it. (Store uses the fully-qualified `Rule` since it has no import slot; update keeps the
  imported short form.)
- **`Html::clean` strips slash-separated event handlers** (`<svg/onload=…>`), not only whitespace-separated
  ones — closing a real bypass of the defense-in-depth rich-text sanitizer.
- **Generated hasMany sync uses `whereKeyNot()`** instead of a hardcoded `'id'` column, so it respects the
  child model's actual primary key.

### Tests
- Slash-separated handler stripping; the soft-deletes unique rule (store + update); the hasMany
  `whereKeyNot` assertion.

## v2.51.13

Accessibility — keyboard + ARIA for the sidebar and global search (WCAG 2.1 AA). This was the one item
deferred from the v2.51 audit.

### Added
- **The sidebar menu is screen-reader navigable.** Collapsible groups carry `aria-expanded` (kept in sync
  by `shell.js`) and `aria-controls` pointing at their treeview's `id`; the active link is marked
  `aria-current="page"`; the toggle is a `role="button"`; decorative icons are `aria-hidden`. Pressing
  `Escape` on a focused open group collapses it.
- **The global search is a proper ARIA combobox + listbox.** Full keyboard navigation — Arrow Up/Down
  through results, Enter to open the highlighted one, Escape to close — with `aria-activedescendant`
  tracking the active option, a `role="search"` landmark + accessible label, and a polite live region
  announcing the result count ("3 results" / "No results").
- **Visible focus rings** (`:focus-visible`) on sidebar links and search results, plus a highlight on the
  arrow-key-active option.

### Tests
- Component render assertions for the sidebar ARIA attributes (`aria-current`, `aria-expanded`,
  `aria-controls`/`id` pairing) and the global-search combobox/listbox roles.

### Upgrade note
- The sidebar's `aria-expanded` is kept in sync by `shell.js`. The initial (server-rendered) state is always
  correct (progressive enhancement), so nothing breaks on upgrade. To also keep it synced **after** a user
  expands a group, existing installs should copy the new toggle handler from
  `stubs/frontend/resources/js/shell.js.stub` into their own `resources/js/shell.js` and rebuild assets — it
  now sets `aria-expanded` on toggle and collapses on `Escape`. New installs get it automatically.

## v2.51.12

Batch D — final audit polish.

### Fixed
- **Global search matches a translatable column in the active locale** (via `json_extract`) instead of
  LIKE-ing the raw JSON blob (which matched across locales + the JSON syntax). Non-JSON columns are
  unchanged; column names are validated as identifiers before the raw expression.
- **The API resource exposes a foreign key's id alongside the related name** (`category_id` + `category`),
  so `?filter[category_id]=…` — already whitelisted by the generated filter list — actually works.
- **`<x-admin-core::select>` passes its placeholder to select2** (`data-placeholder`) so multi-selects show
  it too, not just the single-select's empty option.

### Tests
- Added coverage for SetLocale's **per-user `locale` persistence** ("durable across devices") and
  AutoTranslate's **`rate_limit` cap** on outbound translate() calls — both features were previously untested.

## v2.51.11

Batch C — audit polish (security + correctness).

### Security
- **Generated `image` uploads now carry an explicit mime allowlist** (`jpg,jpeg,png,webp,gif`). The bare
  `image` rule also accepts a script-carrying **SVG** (and bmp); the allowlist blocks it (mirrors the
  hardened `file` rule).
- **2FA recovery codes are compared in constant time** (`hash_equals`) instead of `in_array`, closing a
  timing side-channel.

### Fixed
- **Force-delete is audited distinctly.** A permanent delete was logged as `deleted` (identical to a
  soft-delete); it now records **`force_deleted`**, and a force-delete no longer double-logs the soft
  `deleted` it also fires internally.
- **Generated translatable list/show display uses `ac_localize`** instead of duplicating the locale-resolve
  logic inline — consistent with the FK display and with the same filled-locale fallback.

## v2.51.10

### Fixed
- **`admin-core:make` registers the resource in the database menu when `menu_source=database`.** It only
  edited `config/admin-core.php` (or the legacy Blade sidebar), so a database-menu install never saw a newly
  generated resource in the sidebar (you had to add the `menu_items` row by hand). It now also inserts a
  `menu_items` row — idempotent, default menu only, guarded by the table's presence — and skips the legacy
  Blade-sidebar injection for a database menu. The config edit still runs as the seed for
  `admin-core:menu:import`. (Routes/menu audit finding.)

## v2.51.9

Batch B (audit improvements), part 2 — the master-detail scaffold.

### Added
- **`hasMany` field type generates a master-detail scaffold.** `admin-core:make Order
  --fields="…, lines:hasMany:order_items"` (or `lines:hasMany`, child table inferred from the field name)
  now generates the whole line-items pattern: the parent `hasMany` relation, a `<x-admin-core::repeater>`
  block in the form with an ownership marker, a generated **row-partial starting point**, the service
  `create`/`update` plus a `sync{Relation}()` that reconciles rows (update by id / create new / delete the
  rest), and the request's array validation + blank-row filter. You lay out the child's columns in the
  generated `partials/{field}-row.blade.php`. Codifies the master-detail pattern the audit flagged as
  missing (previously hand-built per resource).

## v2.51.8

Batch B (audit improvements), part 1.

### Added
- **`decimal` fields take precision/scale in the DSL:** `price:decimal:12|4` (pipe-separated — the field
  list is comma-split; defaults to `10,2`). Threaded through the migration column, the `decimal:{scale}`
  cast and the factory.

### Fixed
- **Dynamically-added repeater rows now initialise their field enhancers.** `<x-admin-core::repeater>`
  dispatches an `ac:repeater:added` event on each new row; the **CKEditor** component, the generated
  **select2** form script and the **datepicker** stub listen and enhance the new row (a cloned row used to
  keep a plain, un-enhanced select / date / editor).

## v2.51.7

Hardening + bug-fix pass from a multi-agent audit (adversarially verified findings).

### Security
- **CSV import no longer bypasses form sanitisation.** `import()` validated rows with a bare Validator,
  skipping the store FormRequest's `prepareForValidation` — so rich-text / JSON cells were stored
  unsanitised (stored XSS). Imports now run through `prepareForValidation` (e.g. `Html::clean`), exactly
  like a web submission.
- **Global search is permission-gated.** `Search::query()` skipped no entries, leaking records of resources
  a user cannot list. Each entry is now gated on its permission — an explicit `permission` key, or the
  derived `list-{resource}` convention; set `'permission' => null` on an entry to opt out.
- **Bulk actions are capped.** `bulkDelete` / `bulkRestore` / `bulkForceDelete` / `reorder` accepted an
  unbounded `ids` array (mass-write / DoS); now validated `max:1000`.

### Fixed
- **A translatable `name` no longer crashes generated screens / export.** A `[locale => text]` name echoed
  raw hit `htmlspecialchars(): array given` / `Array to string conversion`. Wrapped in `ac_localize()`
  across: the **trash** view stub, the **`--sortable`** drag panel, **CSV export** of foreign /
  belongsToMany related names, and the **API resource** FK / belongsToMany names.
- **Bulk delete/restore/force-delete are resilient.** One stale id used to 404 and abort the whole batch;
  missing ids are now skipped and the response reports the count actually affected.
- **`reorder()` runs in a transaction** — a mid-loop failure can no longer leave a half-renumbered order.

## v2.51.6

### Docs
- Documented the new **`<x-admin-core::repeater>`** (master-detail rows), plus the previously-undocumented
  **`<x-admin-core::editor>`** (CKEditor 5 + `min-height`) and **`<x-admin-core::page-loader>`** — in the
  README component reference and the in-app `--access` Documentation page's component list.

## v2.51.5

### Fixed
- **`--access` install no longer fails with "table 'permissions' already exists".** `--access` ships its own
  `create_permission_tables` migration (uuid + group_id aware); if Spatie's plain one was also published —
  which the README *told* you to, and `--seed` then ran `migrate` — the two collided. The installer now
  **removes the duplicate** (admin-core's is a strict superset) instead of only warning about it, and the
  README no longer instructs the separate `vendor:publish` of Spatie's permission migration.

## v2.51.4

### Added
- **`<x-admin-core::repeater>`** — repeatable form rows for master-detail forms (a variant's units, an
  order's line items…). You supply a row partial named with the `:index` it's given; the component renders
  it once per existing row, plus a hidden `<template>` that the Add button clones with a fresh unique index,
  and each row gets a Remove button. Add/remove is inline JS (no build step). It posts `name[i][...]`
  arrays (indexes need not be sequential — re-index server-side). Covered by a render test in `ComponentsTest`.

## v2.51.3

### Fixed
- **Generated resources handle a translatable foreign-key display.** `admin-core:make` emitted
  `$row->relation?->name` for a foreign / belongsToMany **column, `<select>` and show row** — which crashed
  with `htmlspecialchars(): array given` once records existed and the related model's `name` was
  `translatable` (a JSON array). Added an **`ac_localize()`** helper (translatable array → current-locale
  string; plain strings pass through) and the generator now wraps every FK display in it.
- **Topnav layout (scss stub):** the sidebar-collapse override never matched (`data-ac-layout` is on
  `<html>`, `ac-sidebar-collapsed` on `<body>`), so collapsing in topnav forced a 2-column grid onto the
  single-column bar and broke the layout — fixed with a descendant selector, and the collapse toggle is now
  hidden in topnav (meaningless there). The topnav bar also gets a `z-index` so its dropdowns sit above the
  table, and the collapsed sidebar top (brand logo / user avatar) centers in the rail.

`composer analyse` 0 errors; 307 tests green.

## v2.51.2

A UI / responsiveness pass — friendlier, smoother, and tidier on phones.

### Added
- **`<x-admin-core::page-loader>`** — a full-page themed-spinner overlay that masks the load/refresh reflow
  and auto-hides on `load` (8s safety timeout). Wired into the layout stub.

### Fixed / improved
- **Editor has a real height** — `<x-admin-core::editor>` gained a `min-height` prop (default 250px); CKEditor 5
  otherwise collapses to ~1 line.
- **Row-actions ⋯ menu no longer renders under the table** — dropped `data-bs-display="static"` so Popper flips
  the menu up near the bottom of a DataTable instead of dropping off the edge.
- **Sidebar no longer flashes on refresh** — the layout applies the saved `ac-sidebar-collapsed` state *before
  paint* (inline script), not after the JS bundle loads.
- **Collapsed sidebar top is centered** — the brand logo + user avatar center in the icon rail (were left-aligned),
  and expand again on hover.
- **Forms stack on phones** — `form-row` makes the label full-width + left-aligned on xs, horizontal from sm up.
- **Friendlier polish + mobile ergonomics** (scss stub) — accent focus rings, smooth interactive transitions,
  44px tap targets, and tighter phone gutters; respects `prefers-reduced-motion`.

`composer analyse` 0 errors; tests green.

## v2.51.1

### Upgrading (important for existing installs)
Several v2.51.0 security/bug fixes live in **published stubs** (code copied into your app by
`admin-core:install` / `admin-core:make`), so they do **not** reach an app generated before v2.51.0 — most
importantly the **settings upload validation** (`app/Http/Requests/Setting/UpdateSettingRequest.php`). To
apply them, re-publish or hand-patch the affected files: the settings request, `settings/index`, and the
generated `index`/`show`/`trash` views (guard-aware `@can`, trash pager). Fixes that live in the package
`src/` (2FA middleware, generator, `WebController`, components) apply automatically on update.

### Polish (deep-audit LOW)
- **Notification redirect is same-host only** — `NotificationController::read()` no longer follows an
  external / protocol-relative `url` from a notification payload (open-redirect hardening).
- **Form components resolve bracket-name validation errors** — `input` / `textarea` / `checkbox` /
  `file-input` + `form-row` normalise `settings[logo]` → `settings.logo`, so a per-field error (e.g. a
  rejected upload) now shows on the field.
- **Menu manager loads the whole tree in one query** — `MenuService::roots()` wires each level's `children`
  in PHP instead of lazy-loading per node (no N+1).
- **Removed a dead per-row `COUNT(users)`** from the roles DataTable (unused column).
- **Auto-translate degrades gracefully** — a misconfigured `translator` driver no longer 500s a multilingual
  write (the middleware skips auto-fill).
- **Locale-column probe is cross-process cached** — `SetLocale` caches the `locale`-column existence (a
  per-process static backed by a day-TTL cache), so the schema is probed ~once/day fleet-wide rather than
  once per worker; the TTL means a later migration adding the column is picked up automatically.

### Tests
- Added: translatable-input render, notification open-redirect, bracket-name error display, menu single-query.

`composer analyse` 0 errors; 306 tests green.

## v2.51.0

A broad adversarial security/correctness/performance audit (multi-agent, 0 false positives) drove this
release. All high-severity issues were gated behind opt-in features, so a default install was never exposed.

### Security
- **Settings uploads are now validated by type.** `UpdateSettingRequest` previously only checked that
  `settings` was an array — uploaded files went straight to the (public) disk keeping their extension, so a
  `manage-settings` admin could upload `.php`/`.svg`/`.html` (stored-XSS / DoS / RCE). Each upload is now
  validated against its setting's type with an explicit `mimes` allowlist + size cap.
- **Generated `file` fields ship a `mimes` allowlist.** `admin-core:make` emitted a bare `file` rule (any
  extension); it now restricts to a document allowlist (no executable/markup types).

### Fixed
- **2FA enforcement no longer bricks the panel or silently bypasses.** `RequireTwoFactor` hardcoded
  `route('admin.profile.index')` + the `admin.` prefix with no `Route::has` guard, so enabling enforcement
  without the `--access` kit — or under a renamed `route.name_prefix` — 500'd every admin page in a lockout
  loop (and enforcement never fired under a custom prefix). It now resolves the configured prefix and degrades
  to a no-op when the profile route is absent.
- **`--portal` resources no longer crash on the index page.** The generated `--sortable` reorder URL and
  `--soft-deletes` trash link hardcoded the `admin.` route prefix; they now use the resource's route prefix.
- **Self-referencing foreign keys are nullable in the migration.** A `parent_id:foreign:<self>` column emitted
  a NOT-NULL FK while the factory seeded `null`, breaking `create()`; it is now `nullable()->nullOnDelete()`.
- **Auto-translate no longer corrupts placeholders.** `admin-core:translate` sent `:seconds`/`:count` to the
  provider, which mangled them; strings carrying a `:placeholder` or `{…}` token are now kept verbatim.
- **Audit logging never breaks the write it observes.** `LogsActivity` now skips when the `activity_logs`
  table isn't migrated and swallows insert failures (mirroring `ErrorLog::capture`).
- **Generated permission buttons are guard-aware.** The index/show action buttons and the row-action dropdown
  used the default guard's `@can`, hiding CRUD controls for users authenticated on a non-default (portal)
  guard; they now resolve against the resource's guard (threaded via `WebController`).

### Performance
- **Trash screen is paginated** (`WebController::trash()` + `trash.stub`) instead of loading every trashed row.
- **`--sortable` index no longer eagerly loads the whole table** on every render (capped).

### Changed (component conversion — folds in the unreleased v2.50.2 work)
- **Settings page uses the shared field components** (`input` / `textarea` / `checkbox` / `file-input`) instead
  of a bespoke grid — the last view hand-rolling field controls. Component conversion is now complete.
- **`<x-admin-core::file-input>` current-file link is localized** (`access.current_file`); removed the unused
  `access.replace_file_hint` key.

`composer analyse` 0 errors; 302 tests green.

## v2.50.1

### Fixed
- **In-app notifications now honour a custom `route.name_prefix`.** The `notifications-bell` component and
  the notifications index view hardcoded `route('admin.notifications.*')` (and the bell's
  `Route::has('admin.notifications.index')` guard), so a consumer that set a non-default
  `config('admin-core.route.name_prefix')` got a broken bell (dead links / the guard hid it entirely). Both
  now read the configured prefix — matching how `global-search` already resolves its route. Added a feature
  test that registers the routes under a custom prefix and asserts the bell links follow it.

## v2.50.0

An adversarial 5-lens multi-agent re-audit of the component coverage found real gaps the earlier
conformance rounds had missed (and rejected one false positive — the generator's `config('class.button.*')`
buttons, which *are* shipped via `install`'s `class.php`). The genuine gaps are closed here.

### New components — the generator no longer emits raw HTML for boolean/file fields
- **`<x-admin-core::checkbox>`** — boolean field row. A hidden `0` is rendered before the box so an
  unchecked box still submits the field; optional `switch` renders a Bootstrap switch. `FieldSet` emits it
  for `boolean` fields.
- **`<x-admin-core::file-input>`** — file/image upload row with a current-value preview (image thumbnail or
  a "current file" link) and `is-invalid` wiring. `FieldSet` emits it for `image`/`file` fields; the private
  `FieldSet::fileInput()` helper is removed.

### Conversions to components that already existed
- **permissions/index → `<x-admin-core::data-table>`** + a `thead` partial — it was the sole AJAX-DataTable
  index still hand-rolling its `<table>`; the intro note now sits above the component. `#permissions_table`
  is preserved, so the DataTables JS is unchanged.
- **Badges → `<x-admin-core::badge>`** — the menu Hidden/Header tags, the profile 2FA enabled badge, and the
  group-permission count badges (index + child partial).
- **Buttons → `<x-admin-core::button>`** — menu add-item, the menu row edit/delete buttons, the menu form
  save button, and the profile change-avatar button. ids, `data-*` and `.ac-menu-edit` pass through via
  `$attributes`, so the menu offcanvas/edit JS is unchanged.
- **notifications empty state → `<x-admin-core::empty-state>`**.

### Activated a dead component
- **`<x-admin-core::global-search>`** is now placed in the topbar nav stub. It self-guards (renders nothing
  until `admin-core.search` is configured and the search route is registered), so fresh installs are unaffected.

### Round-2 re-audit polish (folded in)
A second adversarial pass confirmed the above are regression-free and closed the remaining under-applications:
- **`<x-admin-core::input>` / `form-row` gained a `hint` prop** (muted `form-text` below the control). The
  users form password field now uses `<x-admin-core::input type="password" :hint="…">` instead of a hand-rolled
  input + `<small>` in a form-row slot.
- **group_permissions/index** now renders `<x-admin-core::empty-state>` when there are no permission groups
  (added `no_group_permissions` to the en + km lang files), mirroring menu/index.
- **`FieldSet::showRows()`** emits `<x-admin-core::badge>` for `belongsToMany` detail values (was a raw `<span class="badge">`).

The settings type-switch image/file branch is intentionally left raw: settings uses its own
`col-sm-3 / col-sm-9` grid (label rendered once outside the `@switch`), which is incompatible with the
form-row-based `file-input` without a full settings-form layout refactor.

No behavior change. `composer analyse` 0 errors; 295 tests green.

## v2.49.1

- **Profile crop-avatar modal now uses `<x-admin-core::modal>`** (with a new `centered` prop) instead of
  hand-rolled Bootstrap modal markup — activating the previously-unused `modal` component. The Croppie JS is
  unchanged (it still targets #cropModal / #croppie-area / #crop-save, which pass through the component). No behavior change.

## v2.49.0

- **New `detail-list` / `detail-row` components for show / detail pages.** The generated show view, the
  `admin-core:field` show-view patch, and the error-log detail page now render their key/value tables via
  `<x-admin-core::detail-list>` + `<x-admin-core::detail-row label="…">value</x-admin-core::detail-row>`
  instead of hand-rolled `<table class="table table-bordered"><tr><th>…</th><td>…</td></tr>` markup. This
  closes the last skeleton gap — the generator already emitted `<x-admin-core::input>` for forms but raw
  `<tr>` for show rows; detail views are now fully component-based and restyleable in one place. No behavior change.

## v2.48.3

- **Skeleton consistency (round 3 — completeness).** A final whole-tree re-scan converted the last
  remaining component-able raw markup: every backend **card** wrapper (profile ×4, settings groups,
  permissions, the group-permissions assignment screen) now uses `<x-admin-core::card>`, and the profile
  2FA recovery-codes notice uses `<x-admin-core::alert>`. After this, the only raw HTML left is
  intentionally so (documented): JS-coupled forms/buttons (the menu offcanvas + tree, the avatar
  croppie modal), the customize drawer's bespoke controls, the settings type-switch field renderer, and
  content/detail tables — none of which a component fits. No behavior change.

## v2.48.2

- **Skeleton consistency (round 2).** A completeness re-scan caught views the first pass missed (all
  cosmetic, no behavior change): the access **create / edit** views (roles, users, group-permissions) plus
  **settings**, **error-logs** (index + detail), **activity-logs** and **profile** now use
  `<x-admin-core::page-header>` instead of a hand-rolled `@section('breadcrumb')` — matching the generated
  scaffold and their own index pages; the **users** form fields use `input` / `form-row`; the
  **error-log detail** page uses `page-header` + `card` + `button`; and the **notifications** action
  buttons use `button`. (Left intentionally raw, with reasons: the menu offcanvas form — JS targets fixed
  element IDs; the settings type-switch renderer; the customize drawer's bespoke controls; content/detail
  tables; and the standalone auth/install pages.)

## v2.48.1

- **Skeleton/HTML consistency.** A full multi-agent audit of every package Blade view found 14 localized
  hand-rolled deviations (all cosmetic — no behavior change); refactored them to the shipped
  `x-admin-core::` components: the generated **trash** view now uses `page-header` + `card`; the
  **profile** and **settings** forms use `input` / `button` / `alert`; the **roles** and
  **group-permissions** form partials use `input` / `select` (also fixing a value-precedence bug on the
  name field); the **error-log** and **activity-log** lists use `data-table` (with extracted `thead`
  partials); and the **sidebar** avatar + **notifications** card use `avatar` / `card`. Added a `restore`
  key to `config('class.button')` / `config('class.icon')` for the trash restore actions.

## v2.48.0

- **Two-factor authentication (TOTP).** Opt-in authenticator-app 2FA for the admin login (Google
  Authenticator / Authy) — **off by default**, so existing installs are unaffected. Admins enable it from
  their profile (scan a QR, confirm a code, save single-use recovery codes); the login then asks for a
  6-digit code or a recovery code. Set `admin-core.two_factor.enforce` (env `ADMIN_CORE_2FA_ENFORCE`) to
  require it — admins without confirmed 2FA are redirected to set it up. The secret and recovery codes are
  stored encrypted; remember-me carries through the challenge; the second factor is rate-limited on its own
  key. Ships the `Ngos\AdminCore\Concerns\TwoFactorAuthenticatable` trait (installed onto the User model by
  `--access`), a `RequireTwoFactor` enforcement middleware, a `two_factor` columns migration, the
  `two_factor` config block, and adds the `pragmarx/google2fa` + `bacon/bacon-qr-code` (SVG QR) deps.
  Enable with `ADMIN_CORE_2FA=true`.
- _Hardened (internal security review):_ TOTP codes can't be replayed within their window (last-used
  time-step is tracked); disabling 2FA and regenerating recovery codes require the current password
  (a stolen session can't silently strip the second factor); the `two_factor_secret` / recovery-codes
  columns are hidden from array/JSON/CSV serialization; and enforcement is scoped to admin routes so a
  shared-`web`-guard front-end isn't trapped.

  _Upgrading an existing install:_ re-run `php artisan admin-core:install --access` (adds the trait + the
  migration) and `php artisan migrate`, or add `use Ngos\AdminCore\Concerns\TwoFactorAuthenticatable;` to
  `App\Models\User` and the `two_factor` block to `config/admin-core.php` by hand.

## v2.47.3

- **Drag-drop tree polish + fixes.** The Menu manager and Group Permissions trees now share the same
  themed style: a visible connector line and clearer per-level indent, compact rows, a grip handle, and a
  right-aligned permission-count badge. Three drag fixes too: right-click / middle-click no longer starts a
  drag (left button only), the drop placeholder is pinned to one row instead of ballooning to the dragged
  subtree's height, and the drag clone shows just the single row being moved.

## v2.47.2

- **Bigger nesting indent on the drag-drop tree.** Each nested level now indents ~2.25rem (was ~1rem) so deep
  trees read clearly — a child sits visibly to the right of its parent, with the connector line.

## v2.47.1

- **Clearer drag-and-drop tree nesting.** On the shared `.ac-tree` / `.ac-menu-tree` style, nested levels now
  indent with a connector line down the branch (so a child reads clearly as one level deeper), and the
  collapse/expand toggle is larger + themed (the default was a tiny 25x20 glyph).

## v2.47.0

- **`menu_source=database` is resilient on a fresh database.** The sidebar falls back to the config menu when
  the `menu_items` table is empty (e.g. right after a `migrate:fresh`, before `admin-core:menu:import`), so it
  is never blank; once the table has rows the database menu takes over. (Tip: call `admin-core:menu:import
  --force` from `DatabaseSeeder` in database mode so `migrate:fresh --seed` repopulates the manager too.)
- **Consistent drag-and-drop tree styling.** The Group Permissions tree now uses the same themed nestable
  look as the Menu manager via a shared `.ac-tree` style — both drag-and-drop trees are identical.

## v2.46.0

- **Bulk restore + bulk force-delete on the trash screen.** The trash list gained row checkboxes, a
  select-all, and a bulk action bar — restore or permanently delete the selected rows at once (force-delete
  asks via SweetAlert), mirroring the list's bulk-delete. New `bulkRestore` / `bulkForceDelete` controller
  methods + routes are generated for soft-delete resources.

## v2.45.0

- **Global search (dependency-free, offline).** A topbar search box that LIKE-searches across the resources
  listed in `config('admin-core.search')` — no Scout / external engine. Add `Route::adminCoreSearch()` to your
  admin route group and `<x-admin-core::global-search />` to the header; results are grouped by resource and
  link to each record. A translatable (JSON) name column is matched and labelled in the active locale. The
  box renders nothing until you configure resources + register the route, so it's opt-in.

## v2.44.0

- **API permission: the super-admin (configured `super_role`) now passes API routes too.** `AuthorizeApiPermission`
  previously checked only `hasPermissionTo`, so an admin whose access comes from the super role (or a host
  `Gate::before` keyed on it) was 403'd on API routes while web routes passed. It now short-circuits for the
  super role, resolved on the permission guard.
- **Activity log shows what changed.** The activity-log list gained a "Changes" column rendering the captured
  `old → new` diff — the data was already stored in `properties`, just never displayed.
- **Docs:** the README `--fields` table now lists `richtext`, `translatable`, and the `foreign:table`
  self-reference / tree form (matching `--list-fields`).
- Removed a duplicate docblock on `FieldSet::prepareBody()`.

## v2.43.1

- **Security hardening (follows up the v2.43.0 richtext feature; from a package audit).**
  - The CKEditor CDN script now carries an SRI `integrity` hash + `crossorigin="anonymous"`, so a tampered
    CDN is rejected by the browser; the component documents how to self-host for offline/air-gapped installs.
  - Richtext is sanitized on save via the new `Ngos\AdminCore\Support\Html::clean()` (drops
    `<script>`/`<style>`/`<iframe>`/`<object>`… elements, inline `on*` event handlers, and
    `javascript:`/`data:` URLs) before it is stored and echoed raw on the show page — defense-in-depth
    against stored XSS. For untrusted input, swap in a full HTML sanitizer (e.g. HTMLPurifier) there.
  - **Portal login now throttles brute-force** (5 attempts per email+IP, then a short lockout), matching the
    main admin login — generated portals previously shipped an unthrottled login endpoint.

## v2.43.0

- **Rich-text editor: `richtext` field type + `<x-admin-core::editor>` component.** `--fields="body:richtext"`
  scaffolds a CKEditor 5 WYSIWYG field — a `text` column storing HTML, the editor component in the form
  (CKEditor's classic build loaded from the CDN only on pages that use it; swap the `src` to self-host for
  offline), a plain-text preview in the list (tags stripped + truncated), and the stored HTML rendered on the
  show page. Use the component anywhere: `<x-admin-core::editor name="content" :value="…" />`.

## v2.42.0

- **Live sidebar after a menu reorder — no page refresh.** In the Menu manager, dragging to reorder/nest
  saves via AJAX; the sidebar now re-renders in place on save (fetches the page, swaps `.ac-nav`), so the new
  order/structure shows immediately when `menu_source=database`. (Edit and delete already reload the page.)

## v2.41.0

- **All confirmations use SweetAlert, never the native browser `confirm()`.** Added a reusable global handler
  in the app layout: any `<form data-confirm="…">` or `<a data-confirm="…">` asks via the already-loaded
  SweetAlert before proceeding. The menu-manager delete, the error-log "clear all", and the per-resource
  trash force-delete now carry `data-confirm` instead of `window.confirm()` / `onsubmit="return confirm()"` —
  one consistent themed dialog everywhere, and reusable for any new destructive action (use the loaded
  library, not native JS).
- **Flash messages pop as toastr toasts, matching the AJAX feedback.** Server-side `session('success'|'error'
  |'warning'|'info')` flashes previously rendered as static Bootstrap alert boxes while AJAX actions used
  toastr — now both use toastr, so notifications look the same whether they come from a redirect or a
  background request.

## v2.40.0

- **Generated forms now compose the reusable form components.** `admin-core:make` emits
  `<x-admin-core::input/select/textarea>` (alongside `translatable-input`, `form-row`, `form-actions`) instead
  of hand-written `<input>/<select>/<textarea>` markup, so every generated CRUD form and any custom screen
  share one source of truth for control styling — change a control once, it changes everywhere. Behaviour is
  unchanged: same field types, Air-Datepicker date inputs, select2-enhanced dropdowns, write-once readonly,
  and enum/foreign/many-to-many option lists; the controls just route through the v2.39.0 components. (The old
  `enumSelect`/`foreignSelect`/`manySelect` builders are gone — the `select` component covers all three.)

## v2.39.1

- **Fix: `admin-core:make` skipped the sidebar menu entry when the resource's route appeared in the config
  docblock example.** The shipped config has a commented example (`['route' => 'admin.products.index', …]`),
  and the menu-append idempotency check used a literal `str_contains`, so generating `Product` (or any
  resource whose route matched the example) silently got no menu link. The check now strips block comments
  first, so genuine entries are always added.

## v2.39.0

- **Reusable form-control components.** Added the atomic controls that were the gap in the component set
  (`form-row`, `card`, `modal`, `alert`, `badge`, `tabs`, `data-table`, `status`, … already shipped):
  `<x-admin-core::input>`, `<x-admin-core::select>`, `<x-admin-core::textarea>` and `<x-admin-core::button>`.
  Each field control renders the full labelled row (label + control + validation error) with consistent
  Bootstrap styling + select2 enhancement and merges any extra attributes (placeholder, step, data-adp, …);
  `<x-admin-core::button>` renders a `<button>` or an `<a href>` with variant / size / outline / icon. Use
  them for custom screens (POS, dashboards, bespoke pages) so everything stays visually consistent — change
  a control's style in one place. Rendered + asserted by a new component test.

## v2.38.0

- **Generator: `translatable` field type.** `--fields="name:translatable"` scaffolds a per-locale field end
  to end — a JSON column (array cast), the `<x-admin-core::translatable-input>` widget in the form (one input
  per configured locale + the `_translate` marker the AutoTranslate middleware fills, so you type one language
  and the rest are translated on save), per-locale validation (the default locale required, the others
  optional), a per-locale factory, and list/show rendering in the active locale. A slug derived from a
  translatable `name` uses the default locale (never passes an array to `Str::slug`). This wires up the model +
  generator side that was missing — the translation middleware and components already shipped.

## v2.37.0

- **Generator: self-referencing & explicit foreign-key targets.** The `--fields` DSL now accepts an explicit
  target table on a `foreign` field — `parent_id:foreign:categories` — so you can scaffold a self-referencing
  tree (category → parent category, threaded comments, org charts) or any FK whose column doesn't follow the
  table convention (`author_id:foreign:users`). Previously the table was inferred from the column name
  (`parent_id` → `parents`), which silently broke self-references. With an explicit target: the migration
  emits `->constrained('<table>')`, the `belongsTo` relation + `exists:` rule + form select all resolve to the
  right model, and a **self-referencing factory writes `null`** instead of `Model::factory()` so seeding and
  tests don't recurse infinitely. Conventional `category_id:foreign` is unchanged.

## v2.36.3

- **Docs + i18n polish.** `ARCHITECTURE.md` now documents the three intentional non-CRUD controllers (auth,
  notification, page) and the localization middleware (`SetLocale` / `AutoTranslate`). Localized the last
  three hardcoded hint strings in the menu-manager form (icon / active-pattern / permission) — the admin UI
  is now 100% translatable.

## v2.36.2

- **Complete the dependency declarations** (full audit of `src/` vs `composer.json`). Added the remaining
  first-party packages the code uses directly but was relying on transitively: `composer-runtime-api`
  (`Composer\InstalledVersions` in `admin-core:version`), `illuminate/process` (`Process` in the installer),
  `illuminate/cache` (`Cache` in the menu tree), and `illuminate/auth` (`Auth` + auth exceptions in the
  error log). All are provided by `laravel/framework`, so no behaviour change — the package now honestly
  declares everything it requires.

## v2.36.1

- **Declare the direct dependencies** the translation feature uses: `guzzlehttp/guzzle` (the HTTP client
  behind the MyMemory/LibreTranslate drivers) and `illuminate/translation` (`__()` / `loadTranslationsFrom`).
  Both are already pulled in by `laravel/framework`, so this is a correctness/hygiene fix — no behaviour
  change — making the package honest about what it requires.

## v2.36.0

- **Global soft deletes — opt-in default.** New `config('admin-core.generator.soft_deletes')` (default
  `false`): when on, **every** `admin-core:make` resource gets the `SoftDeletes` trait + `deleted_at`
  column + the trash/restore screen, no flag needed — mirroring `generator.uuid` / `generator.audit`.
  Override per-resource with the new **`--no-soft-deletes`** flag (use it for high-churn tables like sale
  lines or ledger rows that should hard-delete). The existing `--soft-deletes` flag still forces it on.
  Hard delete is unchanged: the trash screen's **Delete permanently** is a true `forceDelete()`, and
  resources generated without soft deletes hard-delete on the normal delete.
- **Translation & multi-language — middleware-based, no public endpoint.** Two features, both driven by
  middleware that auto-registers on the `web` group (works in already-installed apps, no route edits):
  - **Per-user UI language.** `SetLocale` middleware applies each user's language with `App::setLocale()`,
    so one admin runs in English while another runs in Khmer. Switch with a plain `?setlang=km` link
    (only accepts a configured locale) — the middleware persists it to the signed-in user's `locale`
    column (a `users.locale` migration now ships with `--access`, so per-user language is durable across
    devices out of the box; without the column it falls back to the session). Ships
    `<x-admin-core::language-switcher>` for the topbar and starter `lang/en` + `lang/km` files
    (`__('admin-core::admin-core.*')`, publishable via `--tag=admin-core-lang`).
  - **Content auto-translate (bidirectional, multi-language).** `AutoTranslate` middleware fills empty
    per-locale fields **on save** — type a product name in Khmer and English/Thai/… are filled in for you
    (any configured locale pair, either direction). It runs INSIDE the authenticated, CSRF-protected form
    submit, never overwrites what you typed, and caps outbound calls per request. Use the
    `<x-admin-core::translatable-input name="name" />` component (renders one input per locale + the marker).
  - **Driver-based translator** (`config('admin-core.translation.driver')`): **MyMemory** (free, no API key)
    by default, **LibreTranslate** (point at a self-hosted instance to keep data on your servers), or `null`
    to disable auto-translate (UI language still works). Drivers are **fail-safe** — a provider outage
    returns the original text so a save never breaks. API keys live server-side in `.env`, never exposed.
  - **`php artisan admin-core:translate <code>`** — auto-generate a UI language file for a new locale by
    machine-translating the English source through the driver (writes `lang/vendor/admin-core/<code>/admin-core.php`;
    keeps existing translations unless `--force`). So adding a language is: add it to `locales`, run the command, review.
  - **Localized UI strings.** The shared components (form-actions, import-modal, notifications-bell), the
    `WebController` flash messages, the **chrome stubs** (login page, top nav, sidebar, footer), the
    **generated resource views** (index/create/edit/show/trash), and the **access screens**
    (users, roles, permissions, settings, profile, menu manager) now resolve through
    `__('admin-core::admin-core.*')`, as do the **JS dialogs** (SweetAlert delete confirms + toastr toasts,
    with client-side `:count` interpolation) — English output is byte-identical, so they all switch language
    with the user's locale. (Component/controller strings are live immediately; stubs apply to new installs —
    re-publish to update an existing app.) The only text left in English is a handful of technical hint
    lines that embed code snippets.
  - New `config('admin-core.translation')` block (multi-language `locales` map, `driver`, `default`,
    `max_length`/`rate_limit`/`timeout` safety limits). 244 tests (drivers via mocked HTTP + both middlewares + command).

## v2.34.0

- **Realtime (live) notifications — opt-in.** The in-app bell can now update **live** (badge bumps + a toast
  on arrival) instead of only on page load:
  - `AdminNotification` gains a **broadcast** channel — added to `via()` when
    `config('admin-core.notifications.realtime')` is on (or `new AdminNotification(..., broadcast: true)`),
    with a `toBroadcast()` payload matching the stored one. Default is unchanged (database/in-app only).
  - The kit ships **Laravel Echo** wiring (`resources/js/echo.js` + `realtime.js`, `laravel-echo` + `pusher-js`
    deps) that listens on the signed-in user's private channel and updates the bell. Echo + pusher-js are
    **lazy-loaded only when a broadcaster key is set at build time** (`VITE_REVERB_APP_KEY` / `VITE_PUSHER_APP_KEY`)
    — with realtime off they're tree-shaken out, **zero bundle cost** (main JS stays ~15 kB).
  - Supports **Reverb** (recommended) or **Pusher**. Needs a broadcaster + channel auth + a queue worker — see
    the new "Realtime (live bell)" section in the README.
  - New `config('admin-core.notifications.realtime')` (`ADMIN_CORE_REALTIME`). 227 tests (broadcast `via()` +
    `toBroadcast()` covered); both build paths verified (no-key → tree-shaken; key → realtime chunk compiles).

## v2.33.0

- **Image compression (WebP) + configurable storage/CDN for all uploads.** Every image/file upload
  (avatars, `image`/`file` fields, settings images) now goes through one helper,
  `Ngos\AdminCore\Support\Media`:
  - **WebP compression** — uploaded images are downscaled to `max_width` and re-encoded to WebP at high
    quality (`quality`, default 82) via `intervention/image` (now required). Non-images, or any encode
    failure (no GD/Imagick WebP), fall back to storing the original.
  - **Configurable disk + CDN** — `uploads.disk` chooses the filesystem (point it at s3 + CloudFront to
    serve from a CDN), and `uploads.cdn_url` optionally prepends a CDN base URL. All `asset('storage/…')`
    calls (views, `WebController::avatar`, generated views/columns/forms/model accessors) now build URLs via
    `Media::url()`, so there's one place to switch storage/CDN instead of ~12.
  - New `config('admin-core.uploads')`: `disk`, `cdn_url`, `compress`, `max_width`, `quality`
    (`ADMIN_CORE_UPLOAD_DISK` / `ADMIN_CORE_CDN_URL` env).
- **Fix: profile avatar wasn't saving (regression in v2.31.0).** The refactor changed the avatar write from
  `$user->avatar = …; save()` to `$user->update(['avatar' => …])`, but `avatar` isn't in the User's
  `$fillable`, so the mass assignment silently dropped it. Restored a direct assignment. (Caught by
  dogfooding the new upload pipeline.)

## v2.32.0

- **Configurable base model for generated models.** New `config('admin-core.generator.base_model')` (default
  `Illuminate\Database\Eloquent\Model`) — `admin-core:make` now generates models that `extend` it. Point it at
  your own base (e.g. `App\Models\BaseModel` that `use`s shared traits/casts) and every generated model extends
  it, so you don't repeat `use SomeTrait;` across many models. Keep the *logic* in traits (so Spatie's
  `Role`/`Permission`, which can't extend a base, can `use` them too); the base model just bundles them. Default
  behaviour is unchanged. Enables, never imposes — the package still ships traits, your app decides on a base.

## v2.31.0

- **Access "system" screens now follow the project architecture skeleton.** The Menu manager,
  Activity Log, Error Log, Profile and Settings controllers were bespoke `Controller`s with inline
  `$request->validate()` and direct `Model::` calls — out of step with the CRUD resources (Users, Roles,
  GroupPermissions), which go **Controller → Service (`BaseService`) → Model + FormRequests**. They're now
  aligned:
  - **Menu** → `MenuController extends WebController` + `App\Services\Menu\MenuService` (create-with-sort,
    `saveTree` reorder) + `StoreMenuRequest`/`UpdateMenuRequest`.
  - **ActivityLog / ErrorLog** → `WebController` + `ActivityLogService` / `ErrorLogService` (query layer,
    `clear()`); `getData` now sources from `service->query()`.
  - **Profile** → `BaseController` + `ProfileService` (profile/password/avatar) + `UpdateProfileRequest` /
    `UpdatePasswordRequest` / `UpdateAvatarRequest`.
  - **Settings** → `BaseController` + `SettingService` (grouped read, file-aware save) + `UpdateSettingRequest`.

  No behaviour change — same screens, now consistent and testable. Controller test coverage for the Menu
  manager updated to the new layering. (Models intentionally stay on traits/concerns — `HasPublicUuid`,
  `LogsActivity` — not a base model, since `Role`/`Permission` must extend Spatie's classes.) Dogfood-verified:
  all five resolve their service via DI and render; 219 tests + Larastan L5 clean.

## v2.30.2

- **Fix: Menu manager — adding a top-level item 500'd.** `MenuController::store()` read `$data['parent_id']`
  to compute the append position, but the validated payload omits that key when no parent is sent (nullable +
  absent), causing an "undefined array key" on every root-level add. Now defaults to `null`.
- **Test coverage for the Menu manager controller.** New `MenuManagerTest` loads the *published* controller
  stub and exercises store / reorder / update / destroy over HTTP (append-to-level, header items, the
  route-required rule, drag-reorder persisting `parent_id` + `sort` and busting the cache, link-type
  switching, delete) — the gap that let the above bug ship. Suite: 219 passing.

## v2.30.1

- **Front-end build: lazy-load ApexCharts + quiet the chunk-size warning.** ApexCharts (~530 kB) was imported
  eagerly in `app.js`, so it loaded on *every* admin page even though only the dashboard uses it. It's now
  loaded on demand (`import('apexcharts')` when a chart container is present), so the dashboard renders it on
  an `apexcharts:ready` event and every other page drops ~530 kB from its initial load (verified: ApexCharts
  is now a dynamic chunk, not a static import of the entry). The kit's `vite.config.js` also gains the vendor
  `manualChunks` split (jquery / datatables / charts / ui / bootstrap) that the host already had but the stub
  was missing, plus `chunkSizeWarningLimit: 700` for the one legitimately-large (charts) chunk — so a fresh
  `npm run build` no longer prints the "Some chunks are larger than 500 kB" warning. Re-publish / rebuild the
  kit to pick it up.

## v2.30.0

- **Database-driven sidebar menu + Menu manager (`--access`).** The sidebar can now be managed at runtime
  from the panel instead of editing `config/admin-core.php`:
  - A new **Menu manager** screen at `/admin/menu` (System → Menu, gated by `manage-menu`) where admins
    **add / edit / delete** items and **drag to reorder & nest** them (the nestable tree posts to a reorder
    endpoint that writes each row's `parent_id` + `sort`). Each item has a label, Bootstrap icon, a link
    (pick a named **route** or a custom **URL**, or none → a section header), an optional permission gate,
    open-in-new-tab, an active toggle, and nesting.
  - Opt in with **`config('admin-core.menu_source')` = `'database'`** (default `'config'` — nothing changes
    for existing installs; `ADMIN_CORE_MENU_SOURCE` env override). The `admin-core::sidebar-menu` component
    then renders the new `menu_items` table via `Sidebar::database()`, **cached** (`MenuItem::tree()`,
    forgotten on every write) and **permission/route-filtered** exactly like the config menu.
  - **`php artisan admin-core:menu:import`** copies your current `config('admin-core.menu')` into the table
    (headers, nesting, icons, routes/URLs, `can`, `match` all round-trip) so you start from your real menu.
  - Ships with `--access`: a package-owned `Ngos\AdminCore\Models\MenuItem`, a `menu_items` migration, the
    manager controller/views/routes, and the `manage-menu` permission (granted to the `admin` super-role).
    Dogfood-verified end to end (import → manager renders → DB sidebar renders; store/edit/reorder/delete
    persist + bust the cache).
- **Installer guard against a duplicate permission migration.** `admin-core:install --access` now warns (with
  the exact file to delete) when a second `*_create_permission_tables.php` migration exists — e.g. Spatie's
  default published via `vendor:publish` — instead of letting `migrate` fail later with *"table 'permissions'
  already exists"*. (admin-core's `--access` ships its own permission migration, with `uuid` + `group_id`.)

## v2.29.0

- **Responsive DataTables toolbar.** On phones (`≤767px`) the DataTables BS5 integration centers the
  search/length/info/paging and leaves the search input `width:auto`, so it rendered as a small, off-centre
  box. The kit now makes the search fill the row (label + full-width input, left-aligned) and stretches the
  "Columns" button to full width, so the toolbar stays tidy on small screens. (Row/column collapsing was
  already handled by the Responsive plugin — this is just the toolbar.) Front-end-kit (`app.scss`) tweak —
  re-publish / rebuild the kit to pick it up.

## v2.28.0

- **Docs page now explains the sidebar / menu & access workflow.** The most common "huh?" after generating
  a resource is *"I ran `admin-core:make` but I don't see it in the sidebar."* The in-app docs (`/admin/docs`)
  now spells it out: `make` wires the sidebar link, route and permission set for you (no manual menu edit),
  and a new **"don't see it?"** checklist covers the three real causes — a cached config/views
  (`php artisan config:clear route:clear view:clear`), the sidebar **hiding links you lack permission for**
  (sign in as `admin@example.com` or grant `list-{resource}`), and an un-run migration. The "What you can
  build" list also now calls out the `--access` module (auth, users, roles, activity/error logs, settings).

## v2.27.0

- **Hover-to-expand sidebar (collapsed rail).** When the desktop sidebar is collapsed to an icon rail,
  hovering it (or tabbing into it) now temporarily flies the full sidebar back out *over* the content and
  re-collapses on mouse-leave — so you can read the labels and open sub-menus without un-collapsing. Pure
  CSS, no JS: the grid track stays at the rail width, so the main content never reflows; the flyout sits
  above the sticky header (`z-index`) with a soft shadow, and explicit widths on every state keep the
  collapse / expand / hover-slide all animating smoothly. Scoped to the default `sidebar` layout (the
  `topnav` layout opts out) and desktop widths only (mobile keeps the off-canvas drawer).
- **Tighter sidebar user card.** `.ac-user` horizontal padding trimmed (`.65rem` → `.45rem`) so the avatar +
  name sit a touch closer to the edges.

  Both are front-end-kit (`app.scss`) tweaks — re-publish / rebuild the kit (`npm run build`) to pick them up.

## v2.26.0

- **In-app documentation page (`--access`).** A new `/admin/docs` screen gives every signed-in admin a
  built-in usage guide — what you can build, your first resource, the `admin-core:make` / `:field` / `:page`
  / `:portal` commands, the `--fields` DSL (types + modifiers) and the full UI-component catalog — composed
  from the package's own components (`page-header`, `alert`, `tabs`, `card`, `badge`), so the page is also a
  live demo of them. Shipped as an ungated `Route::view` (visible to all admins, no permission), with a
  `Documentation` sidebar entry that minimal installs drop automatically (the sidebar hides items whose route
  doesn't exist).
  - **Fix:** the component-catalog loop used `$component` as its `@foreach` variable, which collides with
    Blade's reserved per-component instance variable — inside the `<x-admin-core::badge>` render scope it
    resolved to the `AnonymousComponent` object and crashed `htmlspecialchars()`. Renamed to `$componentName`;
    added a regression test guarding the stub against echoing any Blade-reserved name (`$component`,
    `$attributes`, `$slot`, `$errors`) as a loop variable. Dogfood-verified: `/admin/docs` renders end to end
    with the sidebar link, tabs and all component badges.

## v2.25.1

- **Friendlier empty state on every list.** The DataTables defaults (in the front-end kit's `theme.js`) now
  render a centered icon + muted message — `No records yet.` (`emptyTable`) and `No matching records.`
  (`zeroRecords`) — instead of the stock "No data available in table", matching the `empty-state` look. New
  `.ac-table-empty` style ships in `app.scss`. Kit-only (re-publish/rebuild to pick it up); verified it
  compiles into the JS + CSS bundles. (`skeleton` stays an available primitive — the dashboard loads its
  counts synchronously, so there's no genuine async-loading moment to wire it into.)


## v2.25.0

- **The `avatar` component is now used across the access module** (instead of hand-rolled avatar markup), so
  every avatar shares one look + initials fallback:
  - the **topbar user menu** and the **profile page** render `<x-admin-core::avatar>` (a stored image, or a
    stable colour + initials when there's none — replacing the old SVG-silhouette / gravatar fallbacks);
  - the **users list** gains an **avatar column**, served by a new reusable `WebController::avatar()` helper
    (mirrors `badges()`/`actions()`) + an `admin-core::datatable.avatar` partial — so any resource's
    `getData()` can add an avatar cell with one call.
  - Re-publish/rebuild the front-end kit to pick up the navbar/profile changes. Dogfood-verified: users list,
    `getData`, and profile all render the avatar (initials fallback for the seeded admin).


## v2.24.0

- **New `admin-core:page` command — scaffold a standalone (non-CRUD) page.** The generator covered CRUD
  resources, portals and fields, but a custom page (Reports, Settings, a bespoke dashboard) had to be wired by
  hand. `php artisan admin-core:page Reports` now scaffolds:
  - a thin **invokable controller** (`Backend/ReportsController`),
  - a **Blade view** composing `page-header` + `card` + an `empty-state` placeholder,
  - a **route** under `routes/Web/Backend/Modules/` (auto-loaded → `admin.reports` at `/admin/reports`),
  - and by default a **sidebar menu entry** + a **`view-reports` permission** granted to the super role.
  Multi-word names kebab-case (`"Sales Report"` → `admin.sales-report`); flags `--no-menu`, `--no-permission`,
  `--force`. Covered by tests and dogfood-verified end to end (route registers, permission granted, `/admin/reports`
  returns 200 for the admin and renders the components).


## v2.23.0

- **Three more reusable components** — rounding out the library:
  - **`<x-admin-core::tabs>` + `<x-admin-core::tab-pane>`** — Bootstrap content tabs for multi-section
    pages/forms (labels as an `id => label` map, one pane per id; `:pills` for the pill style). Distinct from
    `filter-tabs`, which drives a DataTable column search.
  - **`<x-admin-core::avatar>`** — a round photo, or a stable colour + initials circle when there's no image.
  - **`<x-admin-core::badge>`** — a small count/label badge (`tone` → Bootstrap `text-bg-*`, optional `pill`).
  - New `.ac-avatar` styles ship in the front-end kit's `app.scss`; `tabs`/`badge` reuse stock Bootstrap. All
    three are covered by render tests and documented in the README. The component library is now 20 primitives.


## v2.22.0

- **Four more reusable UI components** — the structural primitives that were still missing:
  - **`<x-admin-core::skeleton>`** — animated loading-skeleton placeholders (`type` text/card/table; shimmer
    is dark-mode aware) to show while content loads.
  - **`<x-admin-core::empty-state>`** — a centered "nothing here yet" block (icon + title + message + optional
    action slot) for empty lists/sections.
  - **`<x-admin-core::alert>`** — an inline contextual message with a leading icon and optional dismiss
    (info/success/warning/danger; `error` → danger).
  - **`<x-admin-core::modal>`** — a reusable Bootstrap modal shell with title/body/footer slots and a `size`.
  - New `.ac-skeleton` / `.ac-empty` styles ship in the front-end kit's `app.scss` (re-publish/rebuild to pick
    them up); `alert` and `modal` reuse stock Bootstrap. All four are covered by render tests and documented
    under "UI components & theme" in the README.


## v2.21.1

- **Fix: enum `<select>` fields weren't enhanced with select2.** Foreign-key and many-to-many selects carried
  `.admin-core-select` (→ select2), but the enum select was a plain Bootstrap `form-select`, and the select2
  init only fired when the form had a foreign/m2m field — so enum dropdowns looked and behaved differently.
  The enum select now carries `.admin-core-select` too, and the form's select2 init is emitted whenever the
  form has any `<select>` (enum, foreign or m2m). Every dropdown is now a consistent select2.


## v2.21.0

- **More reusable Blade components, and the scaffold composes them throughout** (continues v2.20.0):
  - **`<x-admin-core::stat-card>`** — a dashboard KPI card (number + label + icon, optional link, 4 accent
    tones). The dashboard now composes these instead of hand-rolling each `ac-stat` block.
  - **`<x-admin-core::card>`** — a Bootstrap card with optional header/footer slots and a configurable body
    wrapper (`:body-class="''"` for flush content). The generated show/create/edit and the dashboard panels
    use it.
  - **`<x-admin-core::form-actions>`** — the submit + cancel row at the foot of a form.
- **The access module now uses the shared components for consistency**: the users/roles index views adopt
  `<x-admin-core::data-table>`, and all six access create/edit forms (users, roles, group-permissions) use
  `<x-admin-core::card>` + `<x-admin-core::form-actions>`. (The permissions index keeps its inline thead +
  note, and the group-permissions index keeps its bespoke drag/drop layout.)
- Net effect: the dashboard, every generated CRUD view and the access-management screens share one set of UI
  primitives, so a visual change lands in one place. New render tests cover the three components; dogfood-
  verified the generated views, dashboard and retrofitted access views all render in a real app. No behaviour
  change for existing apps until views are re-generated.


## v2.20.0

- **New reusable Blade UI components, and the generated views now compose them** instead of hand-rolling the
  same markup into every resource:
  - **`<x-admin-core::data-table>`** — the list-page card shell (toolbar slot + the `<table>` + a slot under
    it for the sort panel).
  - **`<x-admin-core::export-menu>`** — the CSV export dropdown with a per-column checkbox picker (takes a
    `value => label` map).
  - **`<x-admin-core::import-modal>`** — the Import button + CSV upload modal (optional blank-template link).
  - **`<x-admin-core::form-row>`** — one labelled horizontal field row with the validation error wired; the
    generated forms emit one per field.
  - **`<x-admin-core::status>`** — the `.ac-status` enum pill (accepts a backed-enum instance or a string;
    blank renders nothing), used in the show view.
  Why it matters: a generated index view dropped from ~60 lines of inline card/toolbar/modal markup to a few
  component tags, and an import/export UX change now lands in one package component for **every** resource
  (no regeneration). The components are documented under “UI components & theme” in the README and covered by
  render tests; the per-field form rows and show rows stay generated (resource-specific, owned in the host).
- No behaviour change for existing apps until views are re-generated; the components ship with the package and
  resolve via the `admin-core::` view namespace.


## v2.19.4

- **Fix: some enum values generated a syntactically broken enum class while reporting success.** Each enum
  value becomes a PHP enum case (`case Draft = 'draft';`), so the value's StudlyCase must be a legal, unique
  identifier. Numeric values (`priority:enum:1|2|3` → `case 1 = '1';`), values with punctuation, and pairs
  that collide after StudlyCase (`in-progress|in_progress` → two `InProgress` cases) all produced an
  un-parseable `app/Enums/*` class — yet `make`/`admin-core:field` printed "scaffolded" and only fatalled
  later when the model/validation loaded it. `FieldSet` now validates enum values up front and fails with a
  clear message (naming the offending value and suggesting a prefix like `enum:p1|p2|p3` for numbers), writing
  nothing. Also rejects an empty value list (`status:enum:`). Valid enums are unaffected.


## v2.19.3

- **Fix: a malformed `--fields` DSL silently corrupted the schema instead of erroring.** Enum values are
  pipe-separated (`status:enum:draft|published`); writing them comma/parenthesised (`status:enum(a,b,c)`)
  made the outer comma-split shatter the token, so the values leaked in as their own "fields" — `make` and
  `admin-core:field` then created real columns named `b` and even `c)` (a literal parenthesis in a column
  name) and ran the migration without complaint. `FieldSet` now validates every parsed token: the name must
  be a valid identifier and the type must be one it knows, otherwise it fails with a clear message naming the
  bad token and the correct enum syntax. `make` / `admin-core:field` surface it as a friendly error and write
  nothing. Valid DSLs (including the canonical `enum:a|b|c`) are unaffected.


## v2.19.2

- **Fix: `admin-core:uninstall --purge` orphaned the entire `--api-auth` footprint.** `install --api-auth`
  publishes `app/Http/Controllers/Api/AuthController.php` + `app/Providers/ApiAuthServiceProvider.php` and
  registers that provider in `bootstrap/providers.php` — but uninstall listed none of them. After a "purge"
  that promises to *delete the files it published*, both files were left on disk and the provider stayed
  registered. The dangling registration is the real hazard: delete the provider file by hand (as a purge is
  expected to) and the app fatals on boot with a missing class.
  - Un-wiring now **un-registers `ApiAuthServiceProvider` from `bootstrap/providers.php`** (it's wiring, like
    the middleware alias, so it goes on a plain uninstall too — the host's own providers are preserved).
  - `--purge` now **deletes the two `--api-auth` files**, so a purge leaves no admin-core artifacts behind.
  - Dogfood-verified: after `install --api-auth` → `uninstall --purge`, both files are gone, zero provider
    registrations remain, and the app boots; a non-purge uninstall drops the registration but keeps the files.

## v2.19.1

- **Fix: generated `--api` route files shipped a broken comment sentence.** The guard token
  (`__AC_PERM_GUARD__`) is a middleware-argument fragment (`,merchant`) — correct in the gate expression,
  but it was also injected into prose. On a plain (web-guard) resource the token is empty, so the comment
  rendered as *"…pins that guard via ."* — a dangling sentence in every generated non-guard API module. The
  comment is now static and accurate (a `--guard=api` resource pins its guard via a second middleware
  argument); the functional gate is unchanged.
- Dogfood-verified the previously unexercised surfaces — `admin-core:uninstall` (un-wires routes/middleware/
  User traits, keeps published files), `admin-core:uninstall --purge` + `admin-core:reinstall` (clean
  round-trip), and `--api-only` (emits only the JSON API, no web controller/views/route; unauth → 401, not 500).

## v2.19.0

- **Fix: the back-office admin was forbidden (403) from every permission-gated API route over Passport.**
  The admin authenticated fine (`/api/login`, `/api/me`) but 403'd on every gated resource: `auth:api`
  switches the active guard to `api`, so both the route's `permission:` middleware *and* the generated
  FormRequest's `authorize()` resolved permissions on the `api` guard — but the admin's permissions live on
  `web`. So a working JSON API was effectively unreachable for the seeded admin out of the box.
  - New **`AuthorizeApiPermission`** middleware resolves the permission on the back-office permission guard
    (`web` by default), not the request's auth guard — so the same admin's existing permissions authorize
    the API too. Generated `--api` routes now gate with it (a `--guard=api` resource pins its guard, keeping
    the multi-portal model intact).
  - Generated **store/update FormRequests now defer authorization to the route middleware** (`authorize()`
    returns `true`) instead of re-checking `can()` on the API auth guard — removing the duplicate gate that
    caused the second 403.
  - Verified end to end on Passport 13: with a web admin's bearer token, `GET/POST/PUT/DELETE /api/<resource>`
    all succeed; no token → 401; lacking the permission → 403.

## v2.18.7

- **Fix: `--api-auth` Passport guidance was outdated (silently created no tables).** The printed/README steps
  said `composer require laravel/passport` → `php artisan migrate # oauth tables`, but Passport 12+ no longer
  auto-loads its migrations — so `migrate` reported "Nothing to migrate" and the next step
  (`passport:client`) died with "no such table: oauth_clients". The guidance now inserts
  `php artisan vendor:publish --tag=passport-migrations` before `migrate`. Verified the full flow end to end
  on Passport 13: `POST /api/login` issues a JWT, `GET /api/me` with it → 200, no token → 401.

## v2.18.6

- **Fix: the layout only rendered `success` flash messages.** All three layouts (`--access`, portal, minimal)
  showed `session('success')` and nothing else — so any controller that flashed `error`/`warning`/`info` was
  silently swallowed and the user saw nothing. They now render all four levels (success → green, error → red,
  warning → yellow, info → blue).
- **Fix: a failed import showed a green "success".** CSV import always flashed `success`, even when every row
  was rejected — so "Imported 0 row(s). Skipped 3: …" appeared in a green alert. It now flashes by outcome:
  all rows in → `success`, a partial import → `warning`, nothing imported → `error` (now actually visible,
  thanks to the layout fix). Found by importing deliberately-bad CSVs in a real app.

## v2.18.5

- **Fix: `admin-core:portal merchant` silently skipped wiring its menu + super-role.** The published config
  ships commented-out *examples* that use `merchant` as the sample portal name, and the command's idempotency
  checks used `str_contains` — so they matched those comments and concluded the portal was "already wired,"
  leaving `config/admin-core.php` untouched (a confusing warning, no menu, no super-role). Since `merchant` is
  *the* example name everywhere, it's the first name a user tries — so the feature looked broken out of the
  box. The checks now match only a real, uncommented entry at line-start, so any portal name (including
  `merchant`) wires correctly and stays idempotent. Found by scaffolding a `merchant` portal in a real app;
  added a regression test that runs against the actual published config (the old test used a bare fixture
  with names that couldn't collide).

## v2.18.4

- **Fix: misleading `--api` guidance sent users to a dead end.** Generating a resource with `--api` before
  `routes/api.php` exists (Laravel 11+ omits it) printed "run `install:api` … the modules then load
  automatically" — but `install:api` writes a *bare* `api.php` with no module loader, so `/api/<resource>`
  stayed 404 and nothing back-filled it. The message now says exactly what works: run `install:api`, then
  **re-run the make** to wire `routes/Api/Modules` (or use `admin-core:install --api-auth`, which does both),
  and notes that Sanctum auth needs `HasApiTokens` on `App\Models\User`. Found by building a real `--api`
  resource end to end (the full CRUD works once those are wired — verified 200/201/200/200/200).

## v2.18.3

- **Fix: the Dashboard sidebar item never highlighted as active.** The dashboard route lives at `/admin`, but
  its menu `match` was `'admin/dashboard'`, which `request()->is()` can never match — so every page highlighted
  its own nav item except the dashboard. Changed the default to `'admin'` (an exact match, so it lights on
  `/admin` without bleeding onto child pages). Found by serving a real app and walking the pages.

## v2.18.2

Two blockers found by building a real app on the package from scratch (fresh Laravel 13 + `install --access`).

- **Fix: the first `admin-core:make` crashed on a fresh `--access` install.** `--access` publishes uuid-aware
  `App\Models\{Permission,Role}` and a permissions table with a NOT NULL `uuid`, but the published config left
  `permission.model` pointing at the plain Spatie model — so the generator's `createPermissions()` inserted a
  permission with no uuid and hit a NOT NULL violation. `install --access` now repoints the published config at
  the `App\Models` classes (the AccessSeeder already used them). Minimal installs keep the Spatie default.
- **Fix: `admin-core:make --tests` generated tests that failed on a fresh `--access` app.** The permissions
  table set `group_id` to `default(1)` with a FK to `group_permissions`; in a fresh `RefreshDatabase` test DB
  (no seeded groups) every permission insert defaulted `group_id=1` and violated the FK. `group_id` is now
  **nullable** (no hard default) — `createPermissions`/`AccessSeeder` still assign the real group, so seeded
  permissions stay grouped (verified), but ad-hoc/transient creation no longer needs a pre-existing group.

## v2.18.1

- **Internal cleanup (no behavior change).** The generated belongsTo sort subquery (`foreignDataColumn`)
  re-derived the related table name with a different expression than `parse()` already stored on the field;
  it now reuses the single canonical `relTable`, so the sort subquery and the `exists:` rule can never drift
  apart if that derivation is ever changed. Output is identical for all real inputs (verified by the
  existing foreign-column tests).

## v2.18.0

- **Error log retention.** The `error_logs` table no longer grows unbounded — `ErrorLog` is now
  `MassPrunable`, and the package registers a daily `model:prune` for it, so captured errors older than
  `config('admin-core.error_log.retention_days')` (default **30**) are trimmed automatically (needs the
  app's scheduler cron). Set `retention_days` to `0` to keep errors forever, or prune on demand with
  `php artisan model:prune --model="Ngos\AdminCore\Models\ErrorLog"`. The schedule registers via
  `callAfterResolving(Schedule::class)`, so it costs nothing on a normal request and is skipped entirely
  when retention is 0.

## v2.17.2

- **Export streams lazily (code-review fix).** `WebController::export()` called `->get()`, loading the
  **entire table into memory** before streaming — on a large table that exhausts memory and defeats the
  point of `streamDownload`. It now streams with `->lazy()` (1k chunks, eager-loading the chosen relations
  per chunk), so memory stays flat regardless of row count. Output is byte-for-byte identical.
- **Fix: empty table now exports a header.** Columns are derived from the table schema instead of the first
  fetched row, so exporting an empty resource yields the proper header row (`id,name,…`) instead of a blank
  file — and no row has to be materialised just to learn the column names.

## v2.17.1

- **Complete the field-type catalog (code-review fix).** The catalog behind `--list-fields` and the v2.15.0
  interactive builder listed 14 types but the DSL supports more — `time`, `url` and `json` were fully
  handled by the generator yet missing from the menu/reference (so `--list-fields` under-reported what the
  DSL accepts and you couldn't pick them interactively). Added all three. `--list-fields` now also documents
  the `~` (write-once) and `@` (system) modifiers and points to the `password`/`auth`/`sku` special types.

## v2.17.0

- **Themed date picker (Air Datepicker).** Generated `date`/`datetime` fields now render as a
  Bootstrap-themed calendar (with a time picker for `datetime`) instead of the browser's unstyled native
  input. The `--access` bundle ships [Air Datepicker](https://air-datepicker.com) (~tiny, zero-dep), and its
  `--adp-*` variables are mapped onto the theme tokens (`--ac-accent`, `--ac-surface`, `--ac-border`, …) so
  the calendar matches your accent **and flips with dark mode** for free.
  - **How it's wired.** `date`/`datetime` are now plain `.js-datepicker` text inputs carrying `data-adp`
    (`date` | `datetime`); a new `resources/js/datepicker.js` auto-attaches the picker on load (idempotent)
    and is exposed as `window.acInitDatepickers(root)` for modal/AJAX-loaded forms. The stored value keeps
    the `Y-m-d` / `Y-m-d H:i` shape the `date` validation rule + the model cast already expect — edits
    round-trip, and it degrades to a normal text field without JS. `time` keeps the native picker.
  - Wiring published by `admin-core:install --access`: `air-datepicker` dependency, the CSS/JS imports in
    `app.js`, the new `datepicker.js`, and the theme mapping in `app.scss`. (Re-run install or `npm install`
    + build to pick it up.) Verified building the host bundle.

## v2.16.0

- **`admin-core:make --list-fields`.** Prints the field types and modifiers the `--fields` DSL accepts
  (a table of the 14 types with descriptions, the `?`/`^`/`#` modifiers, and an example) then exits — no
  resource name required. The DSL is now self-documenting at the terminal instead of only in the README,
  and the catalog is a single source of truth shared with the v2.15.0 interactive builder's menu. Running
  `admin-core:make` with no name now fails with a one-line hint (pointing at `--list-fields`) rather than a
  raw Symfony "Not enough arguments" error.

## v2.15.0

- **Interactive generator (prompt for missing fields).** `admin-core:make Product` on a brand-new resource
  with no `--fields` now builds the field list interactively instead of scaffolding a bare `name` column —
  enter a field name, pick a type from a labelled menu (string/text/integer/decimal/boolean/date/datetime/
  email/enum/slug/image/file/foreign/belongsToMany), answer nullable/unique, repeat until you leave the
  name blank. Your answers are assembled into the `--fields` DSL and generation proceeds as usual. You no
  longer have to know the DSL to get started — easier first contact, in the same spirit as Laravel's own
  `make:*` prompts.
  - **Nothing else changes.** Passing `--fields` skips the prompts; adding a channel to an existing model
    still infers fields from it; and **non-interactive runs (tests, CI, scripts) are untouched** — with no
    TTY the builder is skipped and you get the previous single default `name` field. Foreign fields are
    nudged to the `*_id` belongsTo convention as you go.

## v2.14.0

- **Error log.** New `--access` feature: unhandled exceptions are now captured to an `error_logs` table and
  browsable at `admin/error-logs` (gated by a new `view-error-log` permission) — a DataTable of
  time/type/message/URL with a per-row detail view (full message, `file:line`, stack trace, URL/method,
  user), plus delete and "Clear all". Previously the kit only had an *audit* log (who changed what); there
  was no record of faults.
  - **Zero-config capture.** A `reportable` callback registered on the framework exception handler by the
    package's provider — **no `bootstrap/app.php` edit**. Defensive by construction: if the `error_logs`
    table is absent or anything throws mid-log it no-ops, never masking the original exception.
  - **Quiet by default.** Expected exceptions are ignored (validation, authentication, authorization,
    `ModelNotFound`, 404 and every other 4xx `HttpException`); only genuine faults (5xx / uncaught) are
    recorded. Messages/traces are length-capped and the user id is stored as a string (guard-agnostic).
  - Wiring: new `error_logs` migration published by `admin-core:install --access`; `Error Log` sidebar entry
    under **System**; `view-error-log` granted to the seeded `admin` role.

## v2.13.0

- **Export: pick fields + export belongsToMany.** Two requested improvements to CSV export:
  - **Field picker** — the Export button is now a dropdown with a checkbox per column (id, fields, relation
    names, timestamps). Export only what you tick; leave all checked for everything. It's a plain GET form
    (`?columns[]=`), no JS, and `export()` whitelists the requested columns against the real ones so nothing
    hidden can leak.
  - **belongsToMany export** — many-to-many relations now export too, as the related names joined (e.g. a
    `tags` column = "red, blue"), alongside the existing belongsTo name export. `export()` is now
    Collection-aware (joins m2m, single name for belongsTo). Affects newly generated resources.

## v2.12.0

- **Import: download a blank template.** Users importing a CSV had no way to know which fields to fill (the
  modal just said "match the Export shape" — useless on an empty table). The import modal now links a
  **Download template** button: a header-only CSV of the importable columns — the model's fillable columns
  minus password/`hashed` and image/file columns (which a CSV can't carry), i.e. exactly what `import()`
  accepts. New `importTemplate` route + `WebController::importTemplate()`; gated like import (`create-*`).
  Affects newly generated resources.

## v2.11.17

- **Revert v2.11.16 (redundant).** `theme.js` already sets `responsive: true` in the global DataTables
  defaults (`$.fn.dataTable.defaults`), so tables were *already* responsive on mobile — v2.11.16's per-table
  `responsive: true` just duplicated the default and its premise ("never activated") was wrong. Removed the
  duplication. (Tables remain responsive via the global default; the `pageLength`-from-config wiring in
  v2.11.11 stays — that one genuinely overrides the global `pageLength: 10` with `config('admin-core.pagination')`.)

## v2.11.16

- **Tables are responsive on mobile now.** The `datatables.net-responsive-bs5` plugin was already bundled
  (imported in `app.js`) but no table init enabled it, so on narrow screens lists overflowed instead of
  collapsing columns into an expandable row. Every generated list and the access-kit tables
  (users/roles/permissions/activity) now set `responsive: true`. No desktop change; mobile just works.
  Affects newly generated resources / installed access modules.

## v2.11.15

- **Remove duplicate Passport guidance.** v2.11.14 added Passport setup steps to the `--api-auth` install
  output — but `nextSteps()` already prints them (more completely, including the `api` guard config and the
  `HasApiTokens` trait), so the steps showed twice and the new copy was the less-complete one. Reverted that
  block; the guidance now prints once. (The `composer.json` `suggest: laravel/passport` from v2.11.14 stays —
  that part was a genuine addition.)

## v2.11.14

- **`--api-auth` now tells you how to finish the Passport setup.** Installing API auth scaffolded the
  Passport-based controller/provider/routes but said nothing about the required one-time setup, so `/api/login`
  silently didn't work. It now prints the exact steps (`composer require laravel/passport` if missing,
  `migrate`, `passport:keys`, `passport:client --password`, and the two `.env` vars). Also added a `suggest`
  entry for `laravel/passport` so the optional dependency is discoverable on Packagist. (The provider already
  guards `class_exists(Passport)`, so a missing Passport never fatals.)

## v2.11.13

- **Leaner Composer dist.** Added a `.gitattributes` with `export-ignore` so the tarball `composer require`
  downloads no longer ships the package's own `tests/` (~30 files), `.github/`, PHPStan config/baseline,
  `phpunit.xml.dist` and `RELEASING.md` — only the runtime code (`src`, `stubs`, `config`, `resources`) plus
  docs. Smaller install for every consumer; no functional change.

## v2.11.12

- **Declare all the `illuminate/*` components actually used.** `src/` imports concrete classes from
  `illuminate/notifications` (`DatabaseNotification`, `Notification`) and calls the `Validator`
  (`illuminate/validation`) and `File` (`illuminate/filesystem`) services, but those three weren't in
  `require`. They're always present inside a full Laravel app (so nothing broke), but a published package
  should declare its real dependencies — added them at `^13.0`. No runtime change.

## v2.11.11

- **`pagination` config now actually sets the page length.** `config('admin-core.pagination')` (default 50)
  is documented as the "default DataTables page length", but nothing read it — every list used DataTables'
  built-in default of 10. It's now wired into the `pageLength` of every generated list and the access-kit
  tables (users/roles/permissions/activity), so changing the config changes the rows-per-page. Affects newly
  generated resources / installed access modules.

## v2.11.10

- **Group-permission reorder is resilient to a stale tree.** Dragging the permission tree resolved each node
  with `find($id)->update(...)`; if a group had been deleted elsewhere mid-drag, the missing id 500'd the
  whole reorder. The lookups are now nullsafe (`?->update`), so a vanished node is skipped and the rest of the
  reorder still applies. Affects newly installed access modules.

## v2.11.9

- **Fix the dashboard's getting-started text.** The default dashboard told you to add a generated resource to
  the sidebar by editing `layouts/app.blade.php` — outdated since the menu became data-driven. `admin-core:make`
  now auto-registers the resource in `config('admin-core.menu')` and routes it, so the text now says to just
  migrate and visit the page. Affects newly installed dashboards.

## v2.11.8

- **`autocomplete` on the password forms.** The login, profile change-password and user create/edit forms now
  set the right `autocomplete` hints (`username` / `current-password` / `new-password`), so password managers
  fill and save correctly and the forms meet the WCAG "identify input purpose" guideline. Affects newly
  installed access modules.

## v2.11.7

- **Login is now rate-limited.** The access-kit login accepted unlimited password attempts (brute-forceable).
  It now throttles 5 failed attempts per email+IP, then locks out for a short window (cleared on a successful
  login) — the same pattern Laravel Breeze uses. Affects newly installed access modules.

## v2.11.6

- **Validate the profile avatar upload.** `updateAvatar` accepted any base64 string and stored whatever it
  decoded to as the user's `.jpg` — so arbitrary (non-image) bytes could be saved, unbounded in size. It now
  rejects data that isn't a real image (`getimagesizefromstring`) and caps it at 5 MB. Affects newly installed
  access modules. (Verified the password update is already safe — Laravel's `hashed` cast doesn't re-hash an
  already-hashed value, so there's no double-hash.)

## v2.11.5

- **More CSV round-trip fixes (time + file columns).**
  - A `time` field's rule was `date_format:H:i`, but a TIME column exports as `H:i:s` — so re-importing an
    exported time was rejected. The rule now accepts `H:i,H:i:s` (the form posts `H:i`, the export gives
    `H:i:s`; both validate). Affects newly generated resources.
  - On import, `image`/`file` columns are now dropped before validation: a CSV can't carry an upload, so the
    exported path string would fail the `image`/`file` rule and skip the whole row. Now the column is ignored
    (the record keeps its existing file) and the rest of the row imports. Lives on the shared `WebController`,
    so existing resources get it on update.

## v2.11.4

- **Fix: a `false` boolean now round-trips through CSV export/import.** `fputcsv` renders PHP `false` as an
  empty cell, and the import's `boolean` rule rejects `""` (it accepts `0`/`1`/`'0'`/`'1'`) — so exporting a
  row with a false boolean and re-importing it skipped that row. The export now writes booleans as `1`/`0`.
  Lives on the shared `WebController`, so existing resources pick it up on update.

## v2.11.3

- **CSV export includes readable relation names.** A generated resource's export dumped the raw `category_id`
  — opaque in a spreadsheet. It now appends a `category` column (the related `name`) for each belongsTo,
  **next to** the FK id, so the export is readable yet still round-trips: the name column isn't fillable, so
  re-importing the file ignores it and uses the FK. Controlled by a generated `$exportRelations` on the
  controller (override to change); resources without a belongsTo are unaffected.

## v2.11.2

- **`belongsToMany` columns are searchable by the related name too.** Completing v2.11.0: a generated
  many-to-many column (e.g. a product's tags) is now matched by the global search box (`filterColumn` →
  `whereHas` on the related `name`). It stays non-sortable — ordering rows by a multi-value relation is
  ambiguous. Same `name`-column assumption as the display. Affects newly generated resources.

## v2.11.1

- **Fix: the JSON API list no longer N+1s on relations.** A generated `Resource` exposes `category` via
  `$this->category?->name`, but `ApiController::index` queried without eager-loading — so listing N records
  fired N extra relation queries (the web DataTable already eager-loads; the API didn't). `ApiController` now
  has a `$with` array it eager-loads on the list, and the generator populates it from the resource's
  belongsTo/belongsToMany relations. Hand-written API controllers are unaffected (`$with` defaults to `[]`).

## v2.11.0

- **Search & sort the list by a related name.** A generated `belongsTo` column was display-only
  (`searchable: false`, `orderable: false`). It's now searchable via the global box (`filterColumn` →
  `whereHas` on the related `name`) and sortable (`orderColumn` → a correlated subquery on the related
  `name`) — so you can find/order products by their category, etc. Assumes the related model has a `name`
  column, the same assumption the form select and list/show display already make. Affects newly generated
  resources (and the `belongsToMany`/image/file columns stay display-only as before).

## v2.10.7

- **Fix: soft-deleting a record no longer destroys its uploaded file.** A generated service with an
  `image`/`file` field deleted the stored file inside `delete()` — but for a `--soft-deletes` resource that's
  a *soft* delete, so the file vanished from disk while the row was only trashed (restore → broken image),
  and a later permanent delete left the file orphaned. For soft-deletable resources the file is now removed in
  `forceDelete()` instead, so a soft delete keeps the file (restorable) and only a permanent delete clears it.
  Non-soft-delete resources are unchanged. Affects newly generated `--soft-deletes` + file resources.

## v2.10.6

- **`uninstall` now cleans up `routes/api.php` too.** It stripped the `admin-core` blocks from `routes/web.php`
  and `bootstrap/app.php` but never touched `routes/api.php`, leaving the `admin-core:api-auth` block (which
  points at the `AuthController` that `--purge` deletes) and the `admin-core:api-modules` loader behind. Both
  marker blocks are now removed on uninstall; your own routes in the file are preserved.

## v2.10.5

- **Fix: `uninstall` left the User model broken.** `revertHasRoles` removed the `HasRoles` *import* but its
  class-body removal matched only the exact `…Notifiable, HasRoles;` — which never matched, because install
  writes `…Notifiable, HasRoles, HasPublicUuid;`. So uninstall left the model `use`-ing `HasRoles` with no
  import (a fatal "Trait not found"), and never removed `HasPublicUuid`. It now strips both traits and both
  imports regardless of order/other traits (Sanctum, Jetstream, …), leaving a clean `use HasFactory,
  Notifiable;`.

## v2.10.4

- **Readable enum labels everywhere.** Enum values rendered with `ucfirst()` (form select, filter tabs) or
  raw (`list`/`show` badges), so a multi-word value like `in_progress` showed as `In_progress` / `in_progress`
  — and inconsistently between screens. All four now use `Str::headline()` → "In Progress", consistently,
  while `data-status` keeps the raw value so your status CSS is unaffected. The filter-tabs component ships
  this on update; generated form/list/show pick it up for newly generated resources.

## v2.10.3

- **`--api` resources now actually load.** `make … --api` wrote the route file to `routes/Api/Modules/`, but
  the loader that `require`s those files was only ever added by `install --api-auth`. So generating an API
  resource for a Sanctum / custom-auth API (no Passport) left `/api/<resource>` 404ing despite the "API
  routes are loaded…" message. `--api` now wires the module loader into `routes/api.php` itself (idempotent,
  same marker as the installer); if `routes/api.php` doesn't exist yet it tells you to run `php artisan
  install:api` instead of silently doing nothing.

## v2.10.2

- **No more red generated test for portal/guard resources.** `--tests` emitted a feature test that
  authenticates a default `App\Models\User` on the `web` guard — correct for an admin resource, but a
  `--guard`/`--portal` resource is gated on a different guard (and a portal has its own user model), so that
  test 403s. `--tests` now skips for guard-scoped resources with a message pointing you at writing one that
  uses the right guard's user + `actingAs($user, '<guard>')`. Default admin resources still get the test
  (verified passing out of the box).

## v2.10.1

- **Clearer error when API auth isn't configured.** The `--api-auth` login proxy returned a generic
  `401 "credentials are incorrect"` for *any* `/oauth/token` failure — so a missing Passport password client
  (a common setup miss) looked like a wrong password. `login()` now checks the client config up front and, if
  absent, returns an actionable message: run `php artisan passport:client --password` and set
  `PASSPORT_PASSWORD_CLIENT_ID` / `PASSPORT_PASSWORD_CLIENT_SECRET`. Affects newly scaffolded `--api-auth`.

## v2.10.0

- **Portal resources now render in their portal's layout.** A `--portal=merchant` resource's views hardcoded
  `@extends('backend.layouts.app')`, so they rendered inside the **admin** layout (admin sidebar/menu) instead
  of the merchant one. They now extend `<portal>.layout`. The portal layout was also completed to host a full
  CRUD page: it yields `contents` (was the singular `content`, which didn't match the resource views), renders
  the `success` flash, adds a `@stack('scripts')` (so DataTables init runs), and loads the built admin-core
  bundle (jQuery/DataTables/select2/SweetAlert) when present — falling back to CDN Bootstrap otherwise. Admin
  (non-portal) resources are unchanged.
  **Existing portals:** re-scaffold the layout (delete `resources/views/<portal>/layout.blade.php` and re-run
  `admin-core:portal <name>`), or rename its `@yield('content')` → `@yield('contents')` and add
  `@stack('scripts')` so resources generated into it render.

## v2.9.6

- **Fix: breadcrumb "Dashboard" link follows the current portal.** `<x-admin-core::page-header>` hardcoded the
  crumb to `admin.dashboard`, so a multi-portal page (e.g. merchant) linked to the *admin* dashboard — wrong
  guard/area — or showed no crumb when admin wasn't installed. It now targets the current portal's dashboard,
  derived from the route name (`merchant.products.index` → `merchant.dashboard`), and accepts a
  `:dashboard="'x.dashboard'"` override. Admin pages are unchanged. Ships in the package component, so it
  applies on update.

## v2.9.5

- **Fix: `install --access` now adds the `HasRoles` trait to non-default User models.** The class-body
  patch matched the trait line by the exact string `use HasFactory, Notifiable;`, so a User with extra or
  reordered traits (Sanctum's `use HasApiTokens, HasFactory, Notifiable;`, Jetstream, etc.) had `HasRoles` /
  `HasPublicUuid` **imported but never applied** — silently breaking roles/permissions, and the re-run guard
  then skipped it. The trait line is now matched flexibly (works with any extra traits or order), and if it
  still can't be found the installer warns you to add `use HasRoles, HasPublicUuid;` by hand instead of
  failing silently. If a prior install left your User model with the import but no class `use`, add it now.

## v2.9.4

- **Audit log now records restores.** `LogsActivity` logged `created`/`updated`/`deleted` but not `restored`,
  so un-deleting a soft-deleted record left no trace ("who recovered this?" was unanswerable). It now logs a
  `restored` entry too. This lives in the package trait, so existing audited resources pick it up on update —
  no regeneration needed. (The hook is inert on models without SoftDeletes.)

## v2.9.3

- **Fix: date/datetime fields now load their value on the edit form.** The inputs rendered
  `value="{{ old('col', $object?->col) }}"`, but the cast makes `$object->col` a Carbon whose string form
  (`Y-m-d H:i:s`) is rejected by `<input type="date">` (wants `Y-m-d`) and `type="datetime-local"` (wants
  `Y-m-d\TH:i`) — so the field showed **empty** when editing an existing record. The value is now formatted
  to each input's shape (`old()` already holds the right shape after a validation error). Affects newly
  generated / `admin-core:field`-added date/datetime fields; for an existing form, format the default, e.g.
  `old('col', $object?->col?->format('Y-m-d'))`.

## v2.9.2

- **Fix: `admin-core:field` now renders added boolean/date/enum columns in the list.** Adding a field to an
  existing resource patched the model, requests, form, header and JS column — but never the controller's
  `getData()`. So a later-added `boolean` showed a raw `true`/`false`, a `date` a raw ISO string, and an
  `enum` an unstyled value, unlike a generated one. The command now also patches `getData()` (the
  server-side renderer + `rawColumns`), so an added field looks identical to one created up front. Affects
  fields added via `admin-core:field`.

## v2.9.1

- **Fix: disabling permissions no longer 403s every create/update.** `config('admin-core.permission.enabled')
  => false` is the documented way to run without permission middleware, and the routes honour it — but a
  generated resource's `StoreRequest`/`UpdateRequest` `authorize()` still called `$this->user()->can(...)`
  unconditionally, so without permissions set up every write was rejected. The generated `authorize()` now
  mirrors the routes: `! config('admin-core.permission.enabled') || $this->user()->can('create-x')`. Affects
  newly generated resources; existing ones can apply the same one-line change to their request `authorize()`.

## v2.9.0

- **Consistent icons across generated pages.** The generated CRUD views, the `class.php` icon defaults, the
  install layout and the sortable/trash/menu markup still used FontAwesome (`fas fa-*`) while the rest of the
  UI (row-action kebab, components) used Bootstrap Icons — so e.g. a show page's Edit icon didn't match the
  list's. Those are now Bootstrap Icons too (`bi bi-*`). FontAwesome stays bundled in the `--access` kit, so
  any `fas fa-*` you add keeps working. Affects newly generated resources / fresh installs.

## v2.8.4

- **Typed list columns now render cleanly.** In a generated DataTable, `boolean` columns showed a raw
  `true`/`false` (while the detail page already showed "Yes/No") and `date`/`datetime` columns showed a raw
  ISO string. Generated lists now render booleans as a Yes/No badge and dates as `Y-m-d` / `Y-m-d H:i`
  (sorting/searching still use the underlying column). Affects newly generated resources.

## v2.8.3

- **Fix: a generated `boolean` checkbox couldn't be turned off.** An unchecked HTML checkbox submits nothing,
  so unchecking a boolean on edit sent no value and the column kept its old `true`. Generated forms now
  emit a hidden `value="0"` just before the checkbox (the checkbox's `1` still wins when checked), so a
  boolean can actually be unchecked. Affects newly generated resources; for an existing form add
  `<input type="hidden" name="<col>" value="0">` immediately before the checkbox (or regenerate the view).

## v2.8.2

- **Fix: `query()` scope now covers the trash, restore, force-delete and reorder paths too.** `BaseService`
  promises that one `query()` override (e.g. a `tenant_id` scope) gates every read and write — but
  `trashedQuery()`, `restore()`, `forceDelete()` and `reorder()` queried the bare model, bypassing it. A
  scoped service could therefore list, restore, force-delete or reorder rows **outside its scope** (e.g.
  another tenant's soft-deleted records). They now all route through `query()`, so the scope holds on every
  path. No API change; override `query()` exactly as before.

## v2.8.1

- **Create / update / delete now confirm.** Those actions (and soft-delete restore/force-delete) previously
  redirected silently — only import/bulk-delete flashed a message. They now flash a `success` message the
  layout already renders, so every write gives feedback out of the box. Customise or translate it by
  overriding `message(string $action)` on the controller (`$action` = `created|updated|deleted|restored`).

## v2.8.0

- **Ready-made `AdminNotification`.** Fire an in-app alert in one line without writing a Notification class:
  `$user->notify(new Ngos\AdminCore\Notifications\AdminNotification(title: …, message: …, url: …, icon: …, extra: [...]))`.
  It targets the `database` channel and feeds the bell + notifications page directly. Hosts that need
  mail/broadcast/queued can still write their own — the UI only needs `toArray()` to return
  `title`/`message`/`url`/`icon`.

## v2.7.1

- **Fix: notifications pagination rendered unstyled.** The notifications page paginated with the
  framework-default paginator, which emits **Tailwind** markup — broken in this Bootstrap 5 theme. It now
  renders Laravel's bundled `pagination::bootstrap-5` view, so the pager is styled out of the box (no global
  `Paginator::useBootstrapFive()` call, so your app's own pagination is left untouched).

## v2.7.0

- **In-app notifications.** `--access` now ships a notifications system on Laravel's database notifications:
  - a `<x-admin-core::notifications-bell />` component for the top bar (unread badge + recent dropdown),
  - a notifications page (`/admin/notifications`) with mark-read / mark-all-read / delete,
  - a `Route::adminCoreNotifications()` macro and a `NotificationController` (queries the notifications table
    directly, so it works for any Notifiable user/guard), and the `notifications` table migration.
  - Send one with `$user->notify(...)` where `toArray()` returns `title` / `message` / `url` / `icon`.
  - The bell renders only when the routes exist and the user is Notifiable. **Existing installs:** re-run
    `admin-core:install --access` to add the table/route/bell (it now patches an already-wired admin group).

## v2.6.6

- **`admin-core:field` can no longer produce a duplicate-column migration.** Its idempotency check was
  `$fillable`-only, so a column that exists on the table but isn't in `$fillable` (a system field, a
  hand-added column, or fillable drift) slipped through — the generated `add_…` migration then failed on
  `migrate` with *Duplicate column name* (SQLSTATE 42S21/1060). It now also checks `Schema::hasColumn`, so an
  existing column is skipped regardless of `$fillable`.

## v2.6.5

- **Fix: a guest hitting `/admin` got a 500 instead of being redirected to login.** Two causes, both fixed:
  - the sidebar's user block read `auth()->user()->name`/`->avatar`/`->getRoleNames()` directly, so it
    threw on a null user — it's now wrapped in `@auth` (and guards `getRoleNames`);
  - `admin-core:install --access` only added the `auth` middleware to the admin route group on the *first*
    wiring, so a "minimal install, then `--access`" left the group public — the user-aware layout then 500'd
    for guests. Install now also adds `auth` to an existing admin group on a re-run, so guests redirect to
    `/login` as intended.

## v2.6.4

- **Column show/hide ("Columns") now lists only real data columns.** The DataTables colvis button had no
  exclusion, so the menu included the select-all checkbox (a blank entry) and the Actions column — and hiding
  Actions stranded the row controls. The checkbox (first) and Actions (last) columns are now marked `.noVis`
  and excluded via the colvis `columns: ':not(.noVis)'` selector. Rebuild the theme (`npm run build`).

## v2.6.3

- **`admin-core:install --access` now silences Bootstrap's SCSS deprecation-warning flood in
  `npm run build`.** The install deliberately doesn't replace your `vite.config.js` (it carries your own
  plugins/Tailwind) — but that meant the kit's `quietDeps` setting never applied, so building admin-core's
  Bootstrap SCSS printed hundreds of Sass deprecation warnings. Install now **injects** a
  `css.preprocessorOptions.scss { quietDeps: true, silenceDeprecations: […] }` block into the existing
  `vite.config.js` (idempotent; warns if it can't). The warnings were always harmless — the build succeeds —
  but the output is now clean.

## v2.6.2

- **Fix: a fresh `admin-core:install --access` no longer crashes with `ViteManifestNotFoundException`.** The
  published layout + login hard-called `@vite(['resources/js/app.js'])`, so the first page load threw before
  `npm run build` had run (e.g. when the install's build step was declined or non-interactive). The views now
  emit `@vite` only when the assets are available (the Vite dev-server `hot` file or a built
  `public/build/manifest.json`), and render without the theme otherwise. Run `npm install && npm run build`
  for the full styling.

## v2.6.1

- **Fix: `admin-core:portal` now writes the per-guard super-role config correctly.** Two bugs in the
  `config('admin-core.permission.guards')` wiring: the idempotency check matched the *menu* entry the
  command also adds (`'merchant' => [`), so the guards entry was skipped — on any real config the super-role
  config wasn't written **at all**; and the insertion only handled an empty `guards => []`, so a **second**
  portal couldn't be added. Now keyed on the guards entry specifically and handles empty *and* populated
  arrays, so multiple portals wire cleanly. (The seeder already granted access, so portals still worked;
  this makes the `--portal` auto-grant config correct too.)

## v2.6.0

- **`admin-core:portal` now scaffolds a factory + seeder, so the portal is loggable out of the box.** After
  `admin-core:portal merchant && php artisan migrate && php artisan db:seed --class=MerchantSeeder` you can
  sign in at `/merchant/login` with `merchant@example.com` / `password` — the seeder creates that account
  **and** a `merchant-admin` super role (on the `merchant` guard) granted every `merchant`-guard permission.
  Re-run the seeder after `admin-core:make … --portal=merchant` to grant the new permissions. The seeder
  resolves the Role/Permission classes via `config('permission.models.*')`, so custom models (e.g. uuid-keyed)
  are honoured. Previously the portal scaffolded but had no user/role — you couldn't actually log in.

## v2.5.0

- **New `admin-core:portal <name>` — scaffold a whole separate-guard portal in one command.** Stands up a
  second admin area (merchant, vendor, …) end-to-end:
  - a guard-scoped user model (`App\Models\Merchant`, `$guard_name`, hashed/hidden password) + migration;
  - a working login (guard-aware `LoginController` + standalone login view) and a guarded dashboard;
  - a portal layout that renders the portal's permission-filtered menu (`<x-admin-core::sidebar-menu
    menu="merchant" guard="merchant" />`);
  - registers the `merchant` guard + `merchants` provider in `config/auth.php`, a `routes/web.php` route
    group (`prefix/name 'merchant'`, `auth:merchant`, globbing `routes/Merchant/Modules/*.php`), and the
    `menus.merchant` + per-guard super-role config.
  - Then `admin-core:make Order --portal=merchant` (v2.4.0) generates straight into it. Idempotent.

## v2.4.0

- **`admin-core:make --portal=merchant` — generate a resource *into* a portal, end-to-end.** One flag now
  routes everything to the portal, not just permissions:
  - route module → `routes/Merchant/Modules/` (so the portal's route group owns it);
  - route-names → `merchant.*` (views/scripts/tests) and the controller redirects too — `WebController`
    gained an overridable `$routePrefix`;
  - menu → `config('admin-core.menus.merchant')`; guard → `merchant` (permissions + gates).
  - `--menu` / `--guard` remain as low-level overrides; `--portal` is the one-flag way.
- **Fixes the v2.3.0 half-gap:** `--guard` alone gated permissions but left the route mounted under the
  admin group (`admin.*`), so it 403'd. A portal resource now mounts under its own group and resolves.

## v2.3.0

- **Guard-aware permissions — completes the multi-portal story.** `admin-core:make Order --guard=merchant`
  now scopes the whole resource to a separate auth guard:
  - permissions are created with `guard_name = merchant`;
  - the generated routes gate on that guard — `Route::crud('order', …, 'merchant')` and
    `permission:list-order,merchant` on every action (incl. soft-delete/sort/import/export), so Spatie
    checks the merchant guard rather than the default;
  - the auto-grant targets a **same-guard** super role (`config('admin-core.permission.guards.merchant.super_role')`,
    falling back to the global one), avoiding Spatie's `GuardDoesNotMatch`.
  - New config: `permission.guard` (default guard, `web`) and `permission.guards.<name>` (per-portal overrides).
  - Pairs with the v2.2.0 `<x-admin-core::sidebar-menu menu="merchant" guard="merchant" />` so the menu **and**
    the permissions/routes agree on the guard. Omit `--guard` and nothing changes — single-guard apps unaffected.

## v2.2.1

- **Fix: `admin-core:make --menu=<name>` no longer leaks into the default sidebar.** When the named menu
  couldn't be found (config not published, or no `// admin-core:menu:<name>` marker), the resource silently
  fell through to the default Blade sidebar injection — putting it in the wrong portal. It now warns and
  leaves the default sidebar untouched. The default menu (no `--menu`) still falls back as before.

## v2.2.0

- **Multi-portal menus.** The sidebar now supports more than one portal (admin + merchant + …):
  - **Named menus** — define extra menus under `config('admin-core.menus.<name>')` and render one with
    `<x-admin-core::sidebar-menu menu="merchant" />`.
  - **Guard-aware filtering** — pass the portal's auth guard, `<x-admin-core::sidebar-menu menu="merchant"
    guard="merchant" />`, so the `can` permission checks run against *that* portal's user (not the default
    guard). Single-guard apps are unaffected — `guard` defaults to the current guard.
  - **`admin-core:make … --menu=merchant`** registers the resource in that named menu (at its
    `// admin-core:menu:<name>` marker) instead of the default.
  - The default-menu marker is matched with a word boundary, so generating into the default menu no longer
    touches a named menu's marker.

## v2.1.0

- **Dynamic, permission-aware sidebar menu.** The sidebar is now driven by a `menu` array in
  `config/admin-core.php` and rendered by a new `<x-admin-core::sidebar-menu />` component. Items are
  filtered by `Ngos\AdminCore\Support\Sidebar`: an item is hidden when the user lacks its `can` permission
  (only while `permission.enabled`) or its `route` doesn't exist, treeview parents with no visible children
  are dropped, and section headers left empty are pruned. Supports nested (treeview) groups, `header`
  rows, and `match` patterns for the active state.
  - **`admin-core:make` now registers resources as menu *data*** — it appends an entry to the config `menu`
    (with `can => 'list-…'`), instead of injecting Blade. So generated links are permission-gated like the
    built-in ones (fixes generated items showing to users without access). Falls back to the old Blade
    injection for installs that predate the data-driven menu, so nothing breaks.
  - **Adopt it** by re-publishing the config + sidebar (`vendor:publish --tag=admin-core-config` / the
    install kit), or add a `menu` array yourself — see UPGRADING. Run `config:clear` after generating if
    you cache config.

## v2.0.0

First major release — a stabilisation cut after the v1.28.x fix series. **One breaking change:**

- **Removed the deprecated `CrudController` / `CrudService` aliases** (deprecated since v1.19.0). Extend
  `WebController` / `BaseService` instead — see UPGRADING. Resources generated on v1.19.0+ already do.

Everything else — config, routes, the `admin-core:make` / `:field` / `:install` commands, generated output,
the JSON API, CSV import/export, audit log, and all the v1.28.x correctness/security fixes — is unchanged.
If you're already on v1.28.7 and don't reference the removed aliases, upgrading is a no-op.

## v1.28.7

- **`password` fields are now write-only in the UI.** They were rendered as an index-table column (the
  bcrypt hash sent to the browser via the DataTables JSON) and a detail-view row. They now appear **only**
  in the form (to set the value) — skipped in the table header, DataTable columns and show view, by both
  `admin-core:make` and `admin-core:field`. The filter-tabs column index correctly skips the hidden column.
  (The getData JSON was already covered by the model's `$hidden` from v1.28.5.)

## v1.28.6

- **Security: the activity log no longer records password hashes named anything but `password`.**
  `LogsActivity` only stripped a column literally named `password`, so a `secret:password` field's hash was
  written to `activity_logs` on create/update. It now also excludes the model's `$hidden` columns and any
  `hashed`-cast column (matching the v1.28.5 export fix), so any password field is kept out of the log.

## v1.28.5

- **Security: CSV export no longer leaks `password` hashes.** Export used every DB column, so a resource
  with a `password` field dumped its bcrypt hash into the file. Export now drops the model's `$hidden`
  columns **and** any `hashed`-cast column (protects resources generated before this release too).
- **Generated models now declare `protected $hidden` for password fields** (keeping the hash out of
  `toArray()`/JSON/activity-log output, the way Laravel's `User` hides its password). `admin-core:field`
  adds/extends `$hidden` when you add a password field later.

## v1.28.4

- **Fix: CSV export of a `json`/array-cast column wrote a literal `"Array"`** (plus a PHP *Array to string
  conversion* warning). Such columns are now JSON-encoded in the cell, and the import side decodes array-cast
  columns back, so a `json` field round-trips export → import cleanly.
- **Fix: PHP 8.4 `fputcsv()`/`fgetcsv()` deprecation.** Export/import now pass `escape: ''` (RFC-4180
  quoting — double the quotes, no backslash escaping), silencing *"the $escape parameter must be provided"*.

## v1.28.3

- **`admin-core:field` now wires the `booted()` hook for a `slug`** — a slug added later auto-derives from
  the model's `name` (adds a fresh `booted()` or extends an existing `creating()` closure), instead of
  staying blank until typed.
- **System fields (`@` / `sku` / `auth`) are now skipped by `admin-core:field`** with a note. They're not
  mass-assignable, so the `$fillable` idempotency check can't track them (re-runs would duplicate) and they
  need a `booted()` value-setter — use `admin-core:make … --force` to (re)scaffold those.

## v1.28.2

- **Fix: `admin-core:field` now wires `prepareForValidation()` for `json`/`password` fields.** Adding a
  `json` field inserted the `array` rule but not the decode hook, so the textarea string was rejected by
  validation; adding a `password` field left no blank-drop on update, so saving an edit with the field blank
  overwrote the stored hash. The patcher now adds (or extends) `prepareForValidation()` on the Store/Update
  requests: json is decoded, and a blank password is dropped on update.

## v1.28.1

- **Fix: `admin-core:field` adding a `unique` field broke the UpdateRequest.** The update rule uses an
  unqualified `Rule::unique(...)`, but a resource generated without any unique field has no
  `use Illuminate\Validation\Rule;` import — so the patched request fataled with *Class "…\Rule" not found*
  when validating an update. The patcher now adds the import alongside the rule (once, only when needed).

## v1.28.0

- **New `#` field modifier — add a plain database index.** Suffix a field with `#` to get `->index()` on
  the migration column (e.g. `status:enum:new|paid#`, `placed_at:datetime#`) for columns you filter/sort on
  often. It's a no-op when the column is already `^` unique (a unique constraint indexes itself) or a
  `foreign` (constrained keys self-index), so you never get a double index. Works in both `admin-core:make`
  (create migration) and `admin-core:field` (add migration).

## v1.27.0

- **`admin-core:field` now patches the detail (show) view too.** A field added later was wired into the
  index table, form, requests, model, factory and API — but **not** the resource's `show.blade.php`, so it
  was missing from the detail page. It's now inserted as a row (above the timestamps), enum values rendered
  via `->value` like the generator. Skipped silently if the show view was removed/customised past the anchor.

## v1.26.0

- **Adding a channel no longer needs `--fields`.** When you re-run `admin-core:make` to add the API (or web)
  channel to a resource that already exists, the fields are reconstructed from its model + migration — field
  names/order from `$fillable`, column types from the migration (so `integer`/`time` are detected, not just
  the cast subset), and enum values read from the backed enum class. So `admin-core:make Post --api` on a
  web-only `Post` just works, and adding the API to N resources is an `--api`-only loop. Explicit `--fields`
  still wins; upload (`image`/`file`) columns can't be distinguished when inferring, so pass `--fields` for
  those. Inference only kicks in when `--fields` is omitted **and** the model already exists.

## v1.25.0

- **`admin-core:field` now syncs the API channel too.** When the resource has an `--api` channel, adding a
  field also patches its `JsonResource` (so the field appears in the API payload) and the
  `$searchable`/`$sortable`/`$filterable` whitelists by type — previously the field landed in the DB + web
  admin but was silently absent from the API. No-op for web-only resources.

## v1.24.2

- **`admin-core:field` now skips relation/upload fields instead of half-wiring them.** A `foreign` /
  `belongsToMany` / `image` / `file` field needs wiring the surgical patcher can't add (model relations,
  the controller's `getData` eager-load/`addColumn`, the service's pivot-sync or file-storage) — previously
  it patched the migration/fillable/form/columns but left the relation + controller + service unwired,
  yielding a broken DataTables column / unsaved uploads. It now detects those types, skips them with a
  note (pointing at `admin-core:make … --force`), and still adds any scalar fields in the same call.

## v1.24.1

- **`admin-core:field` now refuses when the table can't exist.** If the resource has no DB table **and** no
  create migration (e.g. it was generated without `--migration` and never migrated), the command would
  generate an `add_…_to_…_table` migration that fails on `migrate` (`relation … does not exist`). It now
  checks up front and aborts before patching anything, pointing you to `admin-core:make … --migration`.
  (A pending create migration is fine — the add migration runs after it.)

## v1.24.0

- **`admin-core:field` — add fields to an existing resource.** `admin-core:make` only scaffolds once; this
  new command does the after-the-fact change you'd otherwise do by hand across migration + model + views.
  `admin-core:field Product "sku:string^, discount:decimal?"` generates an `add_…_to_…_table` migration and
  **surgically patches** the model (`$fillable` + casts), the store/update requests, the form / thead /
  scripts views and the factory — adding only those fields (enum fields also get their backed enum class).
  **Idempotent:** fields already present (in `$fillable`) are detected and skipped, so re-running — or
  passing a mix of existing and new fields — only adds the new ones and never duplicates a column/rule/
  input. Extracted reusable `FieldSet::fieldTh()`/`fieldColumn()`/`fieldCast()`/`fields()` helpers (the
  generator now uses them too).

## v1.23.0

- **`admin-core:install --api-auth` — Passport OAuth2 API auth, scaffolded.** Publishes an
  `Api\AuthController` (`/api/login` password grant, `/api/logout`, `/api/me`) and a guarded
  `ApiAuthServiceProvider` (1h access tokens / 14d refresh, login throttled 6/min, refresh revoked on
  logout), registers the provider, wires `routes/api.php` (auth routes + the `Api/Modules` loader) and
  `bootstrap/app.php`, and switches `admin-core.api.middleware` to `auth:api`. All edits are
  sentinel-wrapped and idempotent. The login proxies the password grant **in-process**, so the client
  secret stays server-side. Since `composer require laravel/passport` can't run from an artisan command,
  the install prints the finishing steps (keys, password client, `.env` client id/secret, the `api`
  guard, the `HasApiTokens` trait). New `admin-core.api.password_client_id`/`password_client_secret`
  config keys (from env).

## v1.22.0

- **Independent channels: `--api-only`.** Generate just the headless JSON API (model/service/requests +
  API controller/resource/routes — no web controller, views, web routes or sidebar link) for an API-first
  or front-end-decoupled project. The three modes: default = web only, `--api` = web + API, `--api-only`
  = API only. **Re-running is additive** (existing files are skipped): add the API to a web resource by
  re-running with `--api`, or add the web channel to an api-only resource by re-running without
  `--api-only` — both channels share the same model/service/requests. `--tests` with `--api-only` is
  skipped with a warning (the generated feature test drives the web routes).

## v1.21.0

- **Enum fields are now backed by a generated PHP enum — code, not schema.** `status:enum:draft|published`
  generates `App\Enums\ProductStatus` (string-backed) as the single source of truth: validation uses
  `Rule::enum(...)` (replacing the duplicated `in:` lists), the model **casts** the attribute to the enum,
  and the form `<select>`, index filter-tabs and factory all iterate its `cases()`. The DB column stays a
  plain `string`, so **adding a value = adding one `case` to the enum file — no migration**, and every
  layer (validation, form, tabs, factory) picks it up automatically.
- `<x-admin-core::filter-tabs>` gains an `:enum` prop (builds the tabs from a backed enum's cases);
  `:tabs` still works for hand-rolled lists.
- **Fix:** CSV export now serialises enum-cast attributes by their value (a `BackedEnum` instance would
  have crashed `fputcsv`).
- Existing resources are unaffected (their `in:` rules keep working); regenerate to adopt the enum style.

## v1.20.2

- **Harden the API list query against array-valued params.** A client sending `?filter[col][]=x` would bind
  an array into `where()` (a 500), and `?search[]=`/`?sort[]=` cast an array to the string `"Array"`.
  `applyFilters` now only acts on scalar values, and `applySearch`/`applySort` ignore non-string input — so
  malformed query params are silently ignored instead of erroring.

## v1.20.1

- **Security: permission-gate every API action.** The generated API routes were guarded only by
  `auth:sanctum`, while `store`/`update` also got a permission check from their FormRequest's `authorize()`
  — so `index`/`show`/**`destroy`** were reachable by **any** authenticated token regardless of its
  `list`/`delete` permission (a token holder could delete via the API without `delete-{resource}`). Each
  API route now carries `permission:{action}-{resource}` (gated on `config('admin-core.permission.enabled')`,
  the same toggle + pattern as the web `Route::crud` macro), so the API and the back office enforce one
  permission model. Regenerate `--api` resources (or add the middleware) to pick this up.

## v1.20.0

- **API list query — search / sort / filter / paginate on the JSON `index`.** `ApiController::index` now
  honours `?search=` (LIKE across `$searchable`), `?sort=col` / `?sort=-col` (whitelisted `$sortable`,
  `-` = desc), `?filter[col]=value` (exact match on whitelisted `$filterable`), and `?per_page=` (clamped
  to `config('admin-core.api.max_per_page')`, default 100). Lives on the shared base, so every `--api`
  resource inherits it. Generated controllers **derive the whitelists from the fields** — text columns are
  searchable, scalar columns + `created_at` sortable, enum/foreign/boolean filterable. Columns outside a
  whitelist are silently ignored, so a client can't order or filter by an arbitrary column. This is what a
  decoupled front-end (Nuxt, mobile) needs to drive a real data table.

## v1.19.1

- **Fix (hybrid keys): the `--access` kit's update requests ignored the unique rule by the wrong column.**
  `UpdateUserRequest` / `UpdateRoleRequest` / `UpdateGroupPermissionRequest` called
  `Rule::unique(...)->ignore($id)`, which ignores by the `id` column — but `$id` is the public **uuid**
  route key. So editing a user/role/group while keeping its email/name read the record's own value as a
  duplicate and failed validation ("already taken"). Now `->ignore($id, 'uuid')`. (Resources generated by
  the field DSL were already correct — they emit `->ignore($this->route('id'), 'uuid')`.)

## v1.19.0

- **Renamed the base classes for clarity — back-compat aliases kept.** The web controller and the
  catch-all service were named after "CRUD", but they do more (export/import/bulk, soft-delete, reorder),
  and the service is simply the base every service extends. The clearer names frame them as *channels over
  shared bases*:
  - `CrudController` → **`WebController`** (the web/HTML channel; its API twin is `ApiController`).
  - `CrudService` → **`BaseService`** (the one service base both channels use — the previous
    `BaseService` + `CrudService` split is collapsed into it).

  The old names remain as **deprecated aliases** (`CrudController extends WebController`,
  `CrudService extends BaseService`), so existing `extends CrudController` / `extends CrudService` code keeps
  working unchanged. Newly generated resources use the new names. The aliases will be removed in a future
  major version.

## v1.18.1

- **Docs: `ARCHITECTURE.md`** — a one-page map of the reusable skeleton (the five base classes, the
  web + JSON API sharing one service, the request lifecycle, a "where do I put X?" cheat sheet, and what
  the generator emits per resource). Linked from the README. Docs only.

## v1.18.0

- **Shared base classes for the web + API surfaces.** Three new abstractions give the Blade admin and the
  JSON API a common spine, so cross-cutting concerns live in one place:
  - **`BaseController`** — the shared controller seam (`$service` + store/update FormRequest bindings).
    Both `CrudController` (web) and `ApiController` (API) extend it.
  - **`ApiController`** — the API twin of `CrudController`: the five JSON actions (index/show/store/update/
    destroy, paginated, uuid-addressed) now live on this base, so generated `Api\…ApiController` classes are
    **thin** (just wire `$service`, `$resource`, `$storeRequest`, `$updateRequest`) — same shape as the web
    controllers over `CrudController`. API behaviour/fixes now live in one class, not regenerated per resource.
  - **`BaseService`** — holds the model binding + the foundational `query()`. `find()` now flows through
    `query()`, so a single `query()` override in a host base service (e.g. a `tenant_id` scope) covers every
    list, lookup, update and delete — for the admin and the API at once.

  Transparent for existing code: `CrudController` / `CrudService` keep the same public surface. Generated web
  resources are unchanged; generated `--api` controllers are now thin subclasses of `ApiController`.

- **`--api` flag — generate a JSON API per resource** for a decoupled front-end (Nuxt, mobile) or a
  multi-tenant merchant portal. `admin-core:make Product --api` writes a `JsonResource`, an
  `Api\…ApiController` (index/show/store/update/destroy, paginated), and a Sanctum-gated `apiResource`
  route file under `api.{plural}.*`. The controller **reuses the web CRUD's `Service` + FormRequests**
  (one source of truth for validation/authorization), and **the public id in every payload is the uuid
  route key — never the bigint id** (non-enumerable across tenants). Passwords and the owner FK are never
  serialised; uploads become public URLs; relations resolve to their `name`. New `admin-core.api` config
  (`middleware` default `['auth:sanctum']`, `per_page` 25) — add a tenant-scoping middleware there for
  multi-tenant. Load the route files by globbing `routes/Api/Modules/*.php` from `routes/api.php`.

## v1.16.0

- **`--tests` flag — scaffold a CRUD feature test per resource.** `admin-core:make Product --tests` writes
  a self-contained `tests/Feature/ProductTest.php` that exercises the resource over HTTP: index + getData
  render, store persists (faking image/file uploads so required-upload resources still pass), update +
  delete addressed by the **public route key**, and the index is forbidden without permission. It builds
  its own permissioned user from `config('admin-core.permission.model')`, asserts soft-deletion for
  `--soft-deletes` resources (else hard delete), and runs green out of the box (pair with `--migration`).

## v1.15.0

- **CSV import — the counterpart to export.** Generated index screens get an **Import** button (a modal
  file upload, gated by `create-*`) and the base `CrudController` gains `import()`: it parses the uploaded
  CSV, maps the header row to columns, keeps only fillable keys (so a round-tripped export with
  `id`/`uuid`/timestamps imports cleanly), strips the UTF-8 BOM, and validates **each row against the
  resource's store rules** — valid rows are inserted, invalid ones are skipped and summarised in a flash
  message. A new `POST import` route is registered per resource.

## v1.14.3

- **Fix: CSV export now leads with a UTF-8 BOM** (and a `charset=UTF-8` content type), so accented and
  non-ASCII text (é, ñ, ü, 中文 …) opens correctly in Excel instead of as mojibake.

## v1.14.2

- **Security: neutralise CSV / formula injection on export.** The CSV export wrote raw cell values, so a
  record whose text field began with `=`, `+`, `-`, `@`, tab or CR (e.g. `=HYPERLINK(...)`) ran as a live
  formula when the file was opened in Excel/Sheets. Such cells are now prefixed with a single quote so the
  spreadsheet treats them as text; genuine numbers (including negatives) are left untouched, so the export
  stays usable.

## v1.14.1

- **Test:** end-to-end HTTP coverage for the **hybrid key strategy** (bigint `id` + public `uuid`). The
  existing controller tests ran against an integer-keyed fixture, so the resolve-by-uuid path — the
  generator's default, and where the edit/delete crashes lived — was never executed. A new suite drives a
  hybrid fixture through update / delete / ajaxDelete / getData addressed by its uuid, and asserts the
  bigint id is *not* a public handle (404). Test-only — no runtime change.

## v1.14.0

- **Five new field types in the `--fields` DSL:**
  - `time` — `time` column, `<input type="time">`, `date_format:H:i` validation.
  - `url` — string column, `<input type="url">`, `url` validation.
  - `slug` — nullable + unique string, `alpha_dash` validation; **auto-derived from `name`** (when present)
    in the model's `creating` hook if left blank.
  - `json` — `json` column cast to `array`; edited as a pretty-printed monospace textarea and decoded in
    `prepareForValidation` so it stores once.
  - `password` — string column cast to `hashed`; `min:8`; a **blank password on update is dropped** so the
    existing hash isn't overwritten (`new-password` autocomplete, value never echoed).

  Each wires through migration, `$fillable`, validation, form control, factory, and casts. Generated
  request classes gain a `prepareForValidation()` only when a `json`/`password` field needs it.

## v1.13.0

- **Generated models now declare a `casts()` method.** Eloquent doesn't auto-cast custom columns, so a
  `boolean` field read back as `1/0` and `date`/`datetime` columns as plain strings (not `Carbon`). The
  generator now emits `casts()` mapping `boolean → boolean`, `date → date`, `datetime → datetime`,
  `decimal → decimal:2`. Omitted entirely when a resource has no castable column (e.g. string-only models
  stay clean). Existing models are unaffected — regenerate or add the method by hand.

## v1.12.1

- **Docs:** README and UPGRADING brought up to date with the features shipped since v1.9.0 — the status
  pill (`.ac-status` / enum columns), the `page-header` `parent`/`parentUrl` sub-page crumb, and the
  current regression-test coverage. Added an UPGRADING note flagging the v1.12.0 hybrid-key edit fix for
  resources generated on an older version (their `edit`/`show` route links still use `$object->id`).
  Docs only — no code change.

## v1.12.0

- **Fix (hybrid keys): the edit form and the show→edit link posted to the bigint `id`, not the public
  route key.** Generated `edit.blade.php` submitted to `route('…update', $object->id)` and `show.blade.php`
  linked `route('…edit', $object->id)`. Under the hybrid strategy the route binds by `uuid`, so saving an
  edit resolved `uuid = <int>` and crashed with an invalid-uuid SQL error. Both now use
  `$object->getRouteKey()`. (Same bug class as the v1.5.8 host fixes — now closed at the generator.)
- **Consistent page headers on create / edit / show.** They previously jumped straight into a bare form
  card (no title/breadcrumb), while `show` used the legacy AdminLTE `@section('breadcrumb')`. All three now
  use `<x-admin-core::page-header>` like the index, with a "Dashboard › {Plural} › {Action}" trail. The
  component gained optional `parent` + `parentUrl` props for that middle crumb; `show` carries Back/Edit in
  its header actions.

## v1.11.0

- **Enum columns now render as status pills.** Previously an enum field (e.g. `status:enum:draft|published|archived`)
  printed as raw lowercase text in the table and the detail screen. The generator now wraps it in a soft,
  dotted `.ac-status` pill — in both the DataTable cell (`editColumn` + raw) and the show view. A new
  token-driven SCSS component colours common status words semantically (published/active → green,
  pending/processing → amber, failed/cancelled → red, archived/inactive → muted) and falls back to a
  neutral pill for any unknown value, so every enum looks deliberate. Pairs with the existing enum
  filter-tabs.

## v1.10.3

- **Fix: a stray sort arrow appeared next to the select-all checkbox.** The DataTables global default
  forced `order: [[0, 'asc']]`, but column 0 is the non-orderable select-all checkbox — so DataTables 2.x
  marked it the active sort column and stamped a `span.dt-column-order` arrow there (not clickable, just
  confusing). The default is now `order: []`: the server returns its natural order and real column headers
  remain sortable on click.

## v1.10.2

- **Test:** a generator regression test now runs `admin-core:make` against a hybrid group-permissions
  schema (NOT NULL `uuid`) and asserts the "{Plural} Management" group is created with a uuid — the gap
  that let the v1.10.1 crash through (the test env had permissions disabled). Tightened the uuid fill to
  `Str::uuid7()` directly (the package requires Laravel 13, where it always exists).

## v1.10.1

- **Fix (hybrid keys): `admin-core:make` crashed creating the "{Plural} Management" group permission.**
  It inserted the group via the query builder (`DB::table(...)->insertGetId()`), which bypasses the
  `HasPublicUuid` model hook — so under the hybrid key strategy the NOT NULL `group_permissions.uuid`
  column blew up (`null value in column "uuid" … violates not-null constraint`). The insert now fills a
  uuid itself when that column is present.

## v1.10.0

- **Summary stat-list component** `<x-admin-core::stat-list>`: a card of "Label …… value [suffix]" rows
  with right-aligned, tabular-aligned numbers — negatives auto-render red, `'strong' => true` emphasises a
  total. For compact financial/metric summaries (invoices, totals, debt, tips) alongside the big stat tiles.

## v1.9.1

- **Fix: wide tables overflowed the viewport** (right-hand columns cut off, DataTables Responsive not
  collapsing). The shell grid's main column used a plain `1fr`, whose `auto` minimum refuses to shrink
  below the content width — so a wide table blew the page past the viewport and Responsive miscalculated.
  Bounded it with `grid-template-columns: … minmax(0, 1fr)` + `min-width: 0` on the content areas, so the
  table is constrained and Responsive collapses columns correctly.

## v1.9.0

- **Segmented filter tabs** `<x-admin-core::filter-tabs>`: a reusable pill control
  (All / Active / Draft …) that runs a server-side DataTables column search.
  `admin-core:make` now drops it onto the generated `index` automatically for the first
  **enum** field — `status:enum:draft|published|archived` yields tabs filtering that column.
  Drop it on any page with `<x-admin-core::filter-tabs table="#x_table" :column="2" :tabs="[...]" />`.

## v1.8.3

- **Extensible row actions.** `actions($model, $resource, $extra = [])` now takes a list of extra
  menu items (`label`, `url`, optional `icon` / `can` / `class`) that render inside the kebab (⋯)
  dropdown above Edit/Delete — so resource-specific actions (e.g. a user's "Change Password") sit in the
  same menu instead of a stray coloured button next to it.
- **Fix (hybrid keys):** custom action links must use `getRouteKey()`, not `->id`. The host Users
  "Change Password" link passed the integer id into a route whose controller resolves by the **uuid**
  route key, throwing `invalid input syntax for type uuid: "1"`. Folded it into the kebab with the
  route key.

## v1.8.2

- **Fix:** the "Columns" toolbar button rendered as a solid grey block. The DataTables Buttons BS5
  integration forces a `btn-secondary` class on every button, on top of our `btn-outline-secondary`.
  Cleared the Buttons default `dom.button.className` so our styling applies — it's a clean outline button now.

## v1.8.1

- **Fix:** the page-header printed a stray "1" above the title. The `breadcrumb` prop defaults to `true`,
  so the `@isset` check was always satisfied and Blade echoed the boolean (`true` → "1"). Simplified to a
  plain `@if ($breadcrumb)` toggle that renders the auto "Dashboard › Title" trail.

## v1.8.0

- **Page-header component** `<x-admin-core::page-header>`: a reusable header with an auto
  "Dashboard › Title" breadcrumb, bold title, muted description and a right-aligned `actions` slot
  (for the primary "Add" button). Replaces the old `@section('breadcrumb')` row across the generated
  `index` and every `--access` list page; the dead AdminLTE card `−`/`✕` tools are gone with it.
- **Table toolbar**: every DataTable now ships a **Columns** (show/hide) button via the DataTables
  Buttons `colvis` plugin, with search top-right and info + rows-per-page + paging on the bottom row —
  configured once in the global DataTables defaults, no per-page wiring. (Adds `datatables.net-buttons`
  + `datatables.net-buttons-bs5`.) The server-side CSV **Export** button stays in the card toolbar.
- **Sidebar count badges**: a `.ac-nav-badge` pill for nav items
  (`<span class="ac-nav-badge">{{ \App\Models\User::count() }}</span>`), accent-tinted when active.

## v1.7.0

- **Customize drawer.** A client-side personalization panel (palette icon in the topbar) with six
  controls, persisted in `localStorage` and applied before paint (no flash): **Theme** (Light / Dark /
  System, with a full dark variant), **Accent colour** (Neutral / Blue / Violet / Rose / Orange),
  **Density** (Compact / Comfortable / Spacious), **Layout** (Sidebar / Top-Nav), **Container**
  (Fluid / Boxed) and **Direction** (LTR / RTL). The theme is now fully token-driven (CSS custom
  properties), so every surface/border/accent flips at runtime — no recompile. New `customize.js` +
  `partials/customize.blade.php`; the topbar moon button is now a quick light/dark toggle owned by the
  same module.
- **Row actions as a kebab (⋯) dropdown.** The DataTable View/Edit/Delete buttons (and the
  group-permission tree) collapse into a compact `⋯` menu instead of a row of buttons — cleaner tables,
  and the column no longer widens with the action set. Delete keeps its existing SweetAlert confirm.

## v1.6.0

- **Clean / neutral theme.** Retuned the shell to a minimal "shadcn"-style look: a **light sidebar**
  (`#fafafa`, hairline right border) instead of the dark slate, a **near-black neutral accent** (`#18181b`)
  for buttons and the active nav state, and **subtle gray (`#f4f4f5`) hover/active fills** in place of the
  indigo tint. Hairline borders throughout, white topbar, and a lighter login. Color is now reserved for
  meaning (status badges, stat-card icon chips). Re-skin the whole thing from a few SCSS variables /
  `--ac-*` tokens at the top of `app.scss` — `--ac-sidebar-bg` (try `#18181b` for a dark sidebar),
  `$primary` (any accent), `--ac-border`/`--ac-hover`.

## v1.5.9

- Refined the theme to a cleaner, more professional look: solid dark-slate sidebar (was a vibrant
  purple gradient), a single muted indigo accent used sparingly, crisper corners, solid buttons, and
  clean white dashboard stat cards with soft-tinted icon chips (instead of the rainbow gradient tiles).

## v1.5.8

- `--access` views (group-permission table + edit/update forms for users/roles/group-permissions) now
  build their edit/delete/update URLs from the model's **route key** (`getRouteKey()`), not the raw `id`.
  With the hybrid key strategy those routes resolve by `uuid`, so the integer-id URLs were 500-ing
  ("invalid input syntax for type uuid").

## v1.5.7

- DataTable row actions (edit/delete/view) now sit in a flex row instead of stacking vertically in a narrow Actions column.

## v1.5.6

- Hide leftover AdminLTE card toggles (`data-lte-toggle` collapse "−" / remove "✕") — dead controls now
  there's no AdminLTE JS behind them.
- Removed the duplicate page title in the topbar (it repeated each page's own heading, e.g. "Profile"
  twice); the topbar now just carries the sidebar toggle + actions.

## v1.5.5

- Fix headings/text rendering in serif: a redundant `:root { --bs-body-font-family: #{...} }` override
  ran the font stack through Sass interpolation, which stripped the quotes off `'Source Sans 3'` and
  produced an invalid unquoted value. Removed it — Bootstrap already sets the (quoted) family from the
  `$font-family-sans-serif` override, so the theme renders in Source Sans 3 as intended.

## v1.5.4

- Fix the topbar user dropdown: it reused the single-icon `.ac-icon-btn` (a centered CSS grid), so the
  avatar, name and caret stacked vertically and oversized. Added a dedicated `.ac-user-btn` flex style so
  they sit in a proper inline row.

## v1.5.3

- Avatars now fall back to a self-contained inline SVG placeholder (no network/file needed) when the
  user has no avatar or the uploaded file is missing/broken (`onerror` handler on the sidebar + topbar
  images) — no more broken-image icons.

## v1.5.2

- The reference `vite.config.js` now sets `css.preprocessorOptions.scss.quietDeps` (+ `silenceDeprecations`)
  so building the theme is clean — Bootstrap 5.3 still uses the old `@import` / `mix()` Sass APIs that
  Dart Sass warns about. Add the same `css` block to your existing `vite.config.js` to silence the noise.

## v1.5.1

- Sidebar guards the Settings / Activity Log links with Route::has, so the themed sidebar no longer
  errors on installs that omit those optional modules (or with an isAdmin gate bypass).

## v1.5.0

- **Custom admin theme (replaces the AdminLTE dependency).** The `--access` front-end is now a bespoke
  "bold branded" shell built on Bootstrap 5 only — a gradient indigo→violet sidebar with pill navigation,
  a sticky blurred topbar, rounded-2xl cards, gradient stat tiles, and a branded login. New `ac-*`
  classes + a small `shell.js` (sidebar collapse w/ persistence, treeview accordion, fullscreen) replace
  all AdminLTE markup/JS; the `admin-lte` npm package is dropped. Bootstrap is now compiled from SCSS so
  the accent flows through every component — retune the whole theme from a couple of SCSS variables.
  Re-theme an existing install with `php artisan admin-core:install --access --force && npm install && npm run build`.

## v1.4.1

- **Typed system helpers** `:auth` and `:sku` (imply `@`): `created_by:auth` adds a nullable `users`
  foreign key set from `auth()->id()`, and `code:sku` adds a nullable string auto-filled with a generated
  code — both wired in the generated `booted()` hook, no TODO to complete. Neither is user-fillable.
- Docs: fixed a dangling README reference in the field-modifiers section.

## v1.4.0

- **Write-once (`~`) and system (`@`) field modifiers.** `~` = settable on create, locked on update
  (fillable + StoreRequest rule, no UpdateRequest rule, readonly input on edit). `@` = set by trusted
  code only — not fillable, not validated, not in the form; scaffolds a `booted()` creating-hook and a
  nullable column. Both enforce on the server, so DOM/console tampering cannot bypass them.
- **Fix (hybrid):** unique-on-update validation now ignores self by the route-key column (uuid), so
  editing a row without changing its unique field no longer false-fails as "already taken".

## v1.3.1

- HasPublicUuid now generates UUID v7 (Str::uuid7) for the public key — time-ordered + RFC 9562 standard.

## v1.3.0

- **Hybrid key strategy** (replaces uuid primary keys). `--uuid` / `generator.uuid` now generate a fast
  **bigint `id` primary key** (lean foreign keys + joins that never bloat) **plus a unique public `uuid`
  column** used in URLs/APIs — so ids are non-enumerable without the index/join cost of uuid PKs. New
  `HasPublicUuid` trait auto-fills the uuid and sets `getRouteKeyName() => 'uuid'`; `CrudService` now
  resolves every action (edit/show/update/delete/bulk-delete/restore/reorder) by the model's route key,
  so plain `id` models are unchanged and hybrid models resolve by uuid automatically. Foreign/pivot keys
  are always `foreignId` (bigint). The `--access` module (users/roles/permissions/group-permissions)
  ships hybrid too.
  **Breaking:** previously `--uuid` made the primary key a uuid; resources generated that way should be
  regenerated (or keep their own migrations).

## v1.2.5

- **Typed settings**: each setting now has a `type` (`text|textarea|number|email|image|file|boolean`)
  that drives the control rendered on the Settings screen — so **Site Logo is a real image upload**
  (with preview), Items Per Page a number field, Support Email an email field, etc. The controller
  stores uploaded files on the `public` disk (replacing the old file) and keeps the existing value when
  no new file is chosen. Adds a `type` column to the settings migration and seeds the defaults with
  sensible types. (Run `php artisan storage:link` for image/file settings.)

## v1.2.4

- **Docs**: corrected README claims that had drifted from the code — `admin-core:make` now auto-grants
  permissions (no re-seed), the removed per-column footer search, the `--sortable` toggle panel (the
  DataTable stays), and the expanded test/CI coverage; added the one-command `--build --seed` tip.
- **Cleanup**: removed the dead `FieldSet::tfoot()` method (orphaned when per-column search was dropped).

## v1.2.3

- **Generator + installer tests** (44 tests total): `admin-core:make` is now covered end to end —
  it asserts the scaffolded files exist, contain no leftover stub tokens, pass `php -l`, and that
  the generated migration actually migrates; plus `--sortable`, `--soft-deletes`, the
  no-duplicate-migration guard, and `--force` overwrite behaviour. `admin-core:install` covers the
  config/migration/view publishing and the bug-prone `routes/web.php` + `bootstrap/app.php`
  string-edits (including idempotency). This is the surface every past release bug lived in.

## v1.2.2

- **Static analysis**: Larastan (PHPStan level 5) via `composer analyse`; a baseline grandfathers
  framework-dynamic false positives (runtime-registered package views, SoftDeletes scopes on the
  generic CrudService, the LogsActivity host trait). LSP signature breaks (e.g. narrowing
  `edit(int|string)` to `int`) are non-ignorable and fail the build.

## v1.2.1

- Expanded the test suite (34 tests): settings get/set/cache (guards the v1.1.9 'incomplete object' regression), soft-delete trash/restore/force, the version command, and more FieldSet cases.

## v1.2.0

- One-command install: `admin-core:install --access` now offers (or with `--build --seed` runs) `npm install && npm run build` and `migrate` + seed for you.
- Premium chrome: Source Sans 3 font, a navbar user dropdown (avatar / Profile / Logout), and a dark/light theme toggle.
- Richer dashboard: an ApexCharts donut of the resource counts.
- Fix: Setting::cached() now caches a plain array (caching a Collection caused an "incomplete object" 500 on pages that read settings).

## v1.1.9

- Settings are now used by the UI: the site name (and optional logo) in the sidebar, login, page title and footer read from the Settings module via a new global `setting('key', 'default')` helper (cached, safe on minimal installs).

## v1.1.8

- admin-core:make now files each resource's permissions under an auto-created "{Plural} Management" group permission, so the Role-edit permission tree stays organised (only when the group-permission feature is installed).

## v1.1.7

- `admin-core:make` now grants the new resource's permissions to the `admin` role automatically (config `permission.super_role`), so you no longer re-run AccessSeeder after every generate.

## v1.1.6

- Generated list tables now use a single global search box; the redundant per-column footer inputs were removed (they duplicated the global search and cluttered the table).

## v1.1.5

- Fix: `admin-core:make --migration` no longer creates a duplicate migration when re-run; it skips if a create_*_table migration already exists (or overwrites that same file with --force) instead of adding a second timestamped one.

## v1.1.4

- `--sortable` now keeps the full DataTable index and adds a "Sort" toggle button next to Create; clicking it reveals a drag-and-drop reorder panel (instead of replacing the table). Search, filters and pagination are preserved.

## v1.1.3

- Profile avatar now uses a Croppie crop-and-upload modal (circular viewport, base64 upload) instead of a plain file input — matching the original app. Adds the `croppie` front-end dependency.

## v1.1.2

- Fix: the --access dashboard used AdminLTE 3 small-box markup (`<div class="icon">`), so the stat-card icons rendered tiny. Switched to AdminLTE 4 `small-box-icon` + added the breadcrumb, matching the framework default.

## v1.1.1

- Fix: `admin-core:install --access` no longer overwrites the host `vite.config.js`, which had dropped `resources/css/app.css` and broke Laravel's default Tailwind welcome page ("Unable to locate file in Vite manifest"). The host config builds admin-core's `app.js` as-is.

## v1.0.0

Initial release.

### Core
- Config-driven `CrudController` + `CrudService` + `Route::crud()` route macro (permission-gated).
- Accepts `int|string` keys (integer and UUID resources coexist).

### Generator (`admin-core:make`)
- `--fields` DSL: string, text, integer, decimal, boolean, date, datetime, email,
  `enum:a|b|c`, `foreign`, `image`, `file`, `belongsToMany`, with `?` (nullable) / `^` (unique).
- Generates migration, model (+relations), form requests, controller, service (with upload/sync
  logic), Blade views, factory, seeder, policy, and a read-only show view.
- `--uuid` (UUID keys) and `--soft-deletes` (trash/restore) flags.
- Auto-registers the resource in the sidebar.
- Every list ships export (CSV), bulk delete, and per-column filters.

### Install (`admin-core:install`)
- Minimal zero-build starter, or `--access` for the full AdminLTE 4 (Vite) kit: login,
  Users/Roles/Permissions/Group-Permissions (with the nestable tree + checktree), profile/account,
  settings module, and a stat-card dashboard.
- Idempotent, sentinel-wrapped edits; `admin-core:version` / `uninstall` (`--purge`) / `reinstall`.

### Tested
- Pest + Orchestra Testbench suite (FieldSet, Route::crud, CrudController).
