# Changelog

All notable changes to `ngos/admin-core` are documented here.

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
