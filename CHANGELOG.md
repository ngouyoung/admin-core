# Changelog

All notable changes to `ngos/admin-core` are documented here.

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
