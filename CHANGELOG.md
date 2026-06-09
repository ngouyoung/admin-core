# Changelog

All notable changes to `ngos/admin-core` are documented here.

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
  column** used in URLs/APIs â so ids are non-enumerable without the index/join cost of uuid PKs. New
  `HasPublicUuid` trait auto-fills the uuid and sets `getRouteKeyName() => 'uuid'`; `CrudService` now
  resolves every action (edit/show/update/delete/bulk-delete/restore/reorder) by the model's route key,
  so plain `id` models are unchanged and hybrid models resolve by uuid automatically. Foreign/pivot keys
  are always `foreignId` (bigint). The `--access` module (users/roles/permissions/group-permissions)
  ships hybrid too.
  **Breaking:** previously `--uuid` made the primary key a uuid; resources generated that way should be
  regenerated (or keep their own migrations).

## v1.2.5

- **Typed settings**: each setting now has a `type` (`text|textarea|number|email|image|file|boolean`)
  that drives the control rendered on the Settings screen â so **Site Logo is a real image upload**
  (with preview), Items Per Page a number field, Support Email an email field, etc. The controller
  stores uploaded files on the `public` disk (replacing the old file) and keeps the existing value when
  no new file is chosen. Adds a `type` column to the settings migration and seeds the defaults with
  sensible types. (Run `php artisan storage:link` for image/file settings.)

## v1.2.4

- **Docs**: corrected README claims that had drifted from the code â `admin-core:make` now auto-grants
  permissions (no re-seed), the removed per-column footer search, the `--sortable` toggle panel (the
  DataTable stays), and the expanded test/CI coverage; added the one-command `--build --seed` tip.
- **Cleanup**: removed the dead `FieldSet::tfoot()` method (orphaned when per-column search was dropped).

## v1.2.3

- **Generator + installer tests** (44 tests total): `admin-core:make` is now covered end to end â
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

- Profile avatar now uses a Croppie crop-and-upload modal (circular viewport, base64 upload) instead of a plain file input â matching the original app. Adds the `croppie` front-end dependency.

## v1.1.2

- Fix: the --access dashboard used AdminLTE 3 small-box markup (`<div class="icon">`), so the stat-card icons rendered tiny. Switched to AdminLTE 4 `small-box-icon` + added the breadcrumb, matching the framework default.

## v1.1.1

- Fix: `admin-core:install --access` no longer overwrites the host `vite.config.js`, which had dropped `resources/css/app.css` and broke Laravel's default Tailwind welcome page ("Unable to locate file in Vite manifest"). The host config builds admin-core's `app.js` as-is.

## Unreleased

- **Static analysis**: Larastan (PHPStan level 5) via `composer analyse`;
  baseline grandfathers framework-dynamic false positives, LSP signature breaks fail the build.

- **Drag-to-reorder** (`admin-core:make --sortable`): adds a `sort` column, a drag-and-drop list index,
  and a `reorder` endpoint backed by `CrudService::reorder()`.
- **Audit trail**: a `LogsActivity` trait + `ActivityLog` model (in the package) record
  create/update/delete with the actor and changed attributes. `admin-core:make --audit`
  (or `generator.audit` config) adds it to a resource; `--access` ships a read-only Activity Log
  viewer; `admin-core:install` publishes the `activity_logs` migration.

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
