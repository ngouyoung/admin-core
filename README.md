# ngos/admin-core

Build a full Laravel admin panel fast. **One command scaffolds a CRUD resource** — model, migration,
controller, form requests, Blade views, permissions and a searchable/sortable/exportable DataTable — on a
clean, branded **Bootstrap 5** theme.

- **One generator.** `admin-core:make Product` → a complete, permission-gated admin screen.
- **Batteries included** (all opt-in flags): login + users/roles/permissions, CSV import/export, soft-deletes,
  audit log, error log, a JSON API, and a dynamic, permission-aware sidebar.
- **Multi-portal.** Stand up a second portal (merchant, vendor…) on its own auth guard in one command.
- **Thin & conventional.** Generated code lives in *your* `App\` namespace and extends a small base
  (`WebController` + `BaseService`) — no magic, easy to read and edit.

> 🚀 **New here? Start with the [step-by-step tutorial](TUTORIAL.md)** — it builds a working catalog admin from
> scratch (install → categories → products with a relation, image & status → roles → a custom action) and
> explains every step. The reference below is for once you know the loop.

## Contents

- 🚀 **[Tutorial](TUTORIAL.md)** — zero to a working admin, hand-held
- [Quickstart](#quickstart) — a working admin + your first resource
- [Installation](#installation) (minimal vs `--access`)
- [Generating a resource](#generating-a-resource) → [field types](#generating-fields-too---fields) ·
  [add a field later](#adding-a-field-later-admin-corefield)
- What every list gets: [export / import / bulk-delete](#every-list-comes-with-export-import--bulk-delete) ·
  [custom actions](#custom-table-actions) · [field-level permissions](#field-level-permissions) ·
  [approval workflow](#approval-workflow) · [reorder](#drag-to-reorder---sortable) ·
  [soft-deletes](#soft-deletes--extras) · [audit](#audit-trail---audit) · [error log](#error-log)
- [Media library](#media-library) — reusable files via `media` / `gallery` fields + `HasMedia`
- [Dashboard widgets](#dashboard-widgets) — config-driven stat / chart / list cards
- [JSON API](#json-api---api) · [API token auth](#api-auth--token-login-admin-coreinstall---api-auth)
- [Multi-portal](#multi-portal) — a separate-guard merchant/vendor area
- [Notifications](#notifications) — in-app bell + notifications page
- [UI & theme](#ui-components--theme) · [Config & commands](#lifecycle-commands)

> Want the layer map? [`ARCHITECTURE.md`](ARCHITECTURE.md) — web + JSON API over one shared service, plus a
> "where do I put X?" cheat sheet.

## Quickstart

A working, authenticated admin in two steps.

**1. Install** (scaffolds login + users/roles/permissions + the theme, then builds assets and seeds an admin):

```bash
composer require ngos/admin-core
php artisan admin-core:install --access --build --seed
```

Log in at **`/login`** with **`admin@example.com` / `password`**.

**2. Generate your first resource:**

```bash
php artisan admin-core:make Product --migration \
    --fields="name:string, price:decimal, status:enum:draft|published"
php artisan migrate
```

Visit **`/admin/products`** — full CRUD with search, sort, status filter-tabs and CSV export, permissions
already granted to the admin role. That's the whole loop; everything below is detail.

> **No styling?** The theme needs a front-end build. The `--build` flag above runs it; otherwise run
> `npm install && npm run build` yourself. (The admin still renders without it — just unstyled.)

## Requirements

- PHP ^8.3, Laravel ^13
- `spatie/laravel-permission` ^8, `yajra/laravel-datatables-oracle` ^13 (pulled in automatically)

## Installation

There are two levels. **Most people want `--access`** (the Quickstart above) — a working, authenticated admin.

### Minimal — just the CRUD engine

```bash
php artisan admin-core:install
```

Scaffolds only the glue generated pages need (idempotent; `--force` to overwrite). You bring your own auth:

| Published | Purpose |
|---|---|
| `config/admin-core.php` | route / view / permission / pagination / menu conventions |
| `config/class.php` | CSS-class map for tables/buttons/icons |
| `resources/views/backend/layouts/app.blade.php` | self-contained CDN starter layout |
| `resources/views/backend/dashboard.blade.php` | minimal dashboard so `admin.dashboard` resolves |
| `routes/Web/Backend/Modules/` | auto-loaded folder for generated resource routes |
| `routes/web.php` | an `admin` route group + module loader (marked `admin-core:routes`) |

### Full access module (`--access`) — login + users/roles/permissions

```bash
php artisan admin-core:install --access --build --seed   # or drop --build/--seed to do them yourself
```

> `--access` ships its own `create_permission_tables` migration (uuid + group_id aware) — **don't** also
> `vendor:publish` Spatie's, or `migrate` fails with *"table 'permissions' already exists"*. If you already
> did, the installer now removes the duplicate for you.

On top of the minimal install, `--access` adds (all in *your* `App\` namespace, yours to edit):

- **Auth** — a session `LoginController` + login view + `/login` `/logout`; the `admin` route group is `auth`-gated.
- **Users / Roles / Permissions** screens built on the CRUD core, with role/permission assignment.
- `App\Models\Role` / `App\Models\Permission` (extending spatie), `HasRoles` on `App\Models\User`, the sidebar,
  and an `AccessSeeder` (an `admin` role with every permission + the `admin@example.com` user).
- The themed front-end kit (`--build` runs `npm install && npm run build`).

`admin-core:make` auto-grants each new resource's permissions to the `admin` role, so there's nothing to re-seed.

### Keeping published assets in sync (`admin-core:doctor`)

The front-end kit (the JS behaviour in `resources/js`, the theme SCSS, the layout Blade) is **copied** out of
the package at install time — so those copies freeze, and a later package fix to, say, `resources/js/datepicker.js`
never reaches an app that installed an older version (**stub drift**). After upgrading the package, run:

```bash
php artisan admin-core:doctor          # report what drifted / went missing (exits non-zero if any)
php artisan admin-core:doctor --diff   # …with a unified diff per file
php artisan admin-core:doctor --fix    # update them to the package version (review with `git diff` after)
```

Behaviour files (`.js`) are flagged distinctly — they're the ones that usually carry bug/security fixes. Your
own theme/layout edits live in these files too, so `--fix` is opt-in (and refuses non-interactively without
`--force`); review with `git diff` before committing, then rebuild assets.

## Generating a resource

```bash
php artisan admin-core:make Product --migration
```

Generates the model, service, controller, form requests, a route module, the Blade views, and the
`list/create/edit/delete-product` permissions. Visit `/admin/products`.

Run it for a **new** resource **without** `--fields` and it prompts you for them interactively — enter a
name, pick a type from the menu, answer nullable/unique, repeat until you leave the name blank — then
generates from your answers. (You don't have to know the `--fields` DSL below to get started; pass
`--fields` to skip the prompts, and non-interactive runs — CI, scripts — just scaffold the default
`name` field.) Prefer to write the DSL by hand? `php artisan admin-core:make --list-fields` prints every
type and modifier it accepts.

### Generating fields too (`--fields`)

Pass a field list and the generator fills in the **migration columns, `$fillable`, validation rules,
form inputs, table headers, and DataTable columns** — a ready-to-use CRUD, no manual edits:

```bash
php artisan admin-core:make Product --migration --fields="\
  name:string, price:decimal?, description:text?, is_active:boolean, \
  status:enum:draft|published, published_at:date?, category_id:foreign"
```

**Field DSL** — `name:type`, comma-separated:

| Type | Migration | Form control | Rule |
|---|---|---|---|
| `string` (default) | `string` | text | `string,max:255` |
| `text` | `text` | textarea | `string` |
| `richtext` | `text` | CKEditor WYSIWYG (sanitized on save, rendered on show) | `string` |
| `integer` | `integer` | number | `integer` |
| `decimal` (`decimal:p\|s`) | `decimal(10,2)` | number (step) | `numeric` + precision/scale |
| `money` (`money:KHR`, `money:@currency`) | `bigInteger` (minor units) | number + currency symbol | `numeric` |
| `computed` (`computed:qty*price`) | — (derived accessor, not stored) | — (read-only) | — |
| `rollup` (`rollup:lines.line_total`) | — (sum of a child relation, not stored) | — (read-only) | — |
| `boolean` | `boolean` default 0 | checkbox | `boolean` |
| `date` / `datetime` | `date` / `dateTime` | Air Datepicker (themed calendar / + time) | `date` |
| `time` | `time` | native time | `date_format:H:i` |
| `email` | `string` | email | `email` |
| `url` | `string` | url | `url,max:255` |
| `enum:a\|b\|c` | `string` | `<select>` from cases | `Rule::enum` (generated backed enum) |
| `slug` | `string` nullable unique | text | `alpha_dash` + unique (auto from `name`) |
| `json` | `json` | monospace textarea | `array` (decoded from the textarea) |
| `translatable` | `json` | multi-locale inputs + auto-translate | `array`, default locale required |
| `password` | `string` | password | `min:8` (hashed; blank on edit = keep) |
| `foreign` (`x_id`), `foreign:table` (self-ref/tree, e.g. `parent_id:foreign:categories`) | `foreignId()->constrained()` | Select2 of related rows | `exists:table,id` |
| `image` | `string` (path) | file input + preview | `image,max:2048` |
| `file` | `string` (path) | file input | `file,max:10240` |
| `belongsToMany` (`m2m`) | pivot table | multi-Select2 | `array` + `exists` |

The model also gets a `casts()` method (`boolean`, `date`, `datetime`, `decimal:2`, `money → MoneyCast`,
`json → array`, `password → hashed`, `enum → its backed enum class`). A `slug` left blank is derived from `name` in the
`creating` hook; a `json` field round-trips through a textarea (decoded in `prepareForValidation`, stored
via the array cast); a blank `password` on **update** is dropped so the existing hash is preserved.

**Date inputs use a themed calendar.** `date`/`datetime` fields render as [Air Datepicker](https://air-datepicker.com)
text inputs (`--access` bundles it) — a Bootstrap-themed calendar (with a time picker for `datetime`) that
matches your accent and flips with dark mode, instead of the unstyled native picker. The submitted value
keeps the `Y-m-d` / `Y-m-d H:i` shape the `date` rule and the model cast expect. The bundle auto-attaches it
to any `.js-datepicker` input on load; for a modal/AJAX-loaded form, call `window.acInitDatepickers(formEl)`.

**Money is stored exactly, as an integer.** `price:money` keeps the amount in **minor units** (cents) in a
`bigInteger` column and casts it to a `Ngos\AdminCore\Support\Money` value object — so amounts and sums stay
exact (no `0.1 + 0.2 = 0.30000000000000004` float drift). The form edits the major amount ("15.00") prefixed
with the currency symbol; the list/show render `$object->price->format()` ("$15.00"). The default currency is
`config('admin-core.money.currency')` (set `ADMIN_CORE_CURRENCY`); pin one column with `price:money:KHR`. Each
currency's decimals/symbol/position/separators live in `config('admin-core.money.currencies')` — **Khmer Riel
(KHR) is 0-decimal**, so ៛15,000 stores as `15000` (not ×100), while USD ($15.00) stores as `1500`. In code:
`$product->price->minor()` (1500), `->major()` ("15.00"), `->format()` ("$15.00"), `->add()/->subtract()/->multiply()`
(exact, same-currency); assigning a number or a `Money` both work (`$product->price = '15.00'`). CSV export writes
the plain `major()` value so a round-tripped import re-parses exactly.

**Per-record currency (multi-currency).** When one column holds amounts in **different currencies row by row**
(a Purchase in USD next to one in KHR), use `total:money:@currency` — the cast reads each row's code from a
sibling `currency` column instead of a fixed one:

```bash
php artisan admin-core:make Purchase --migration \
  --fields="supplier:string, currency:enum:USD|KHR, total:money:@currency"
```

Each row stores its exact minor units for its own currency (USD `1500`, KHR `15000`) and reads back formatted
in that currency (`$15.00`, `៛15,000`); the form shows the record's symbol when editing. The currency column
must be a **user-settable enum or string** holding the code, and — because a write parses the amount with that
column's decimals — **declare it before the money column** so the form/rules fill it first (the make command
warns if not; otherwise a new record's amount is parsed with the default currency). Reads are correct as long
as the whole row is loaded (don't `select()` away the currency column). A `Money` of a currency other than the
row's is refused, never silently reinterpreted.

Two things follow from the amount being stored as bare minor units: **changing only the currency does not
convert** an existing amount (`$15.00` re-saved as KHR reads `៛15`, by design — re-enter the amount), and a
per-record column isn't given an amount **range filter** (one bound can't honour each row's decimals — filter
by the currency column instead).

**Computed fields are derived, not stored.** `total:computed:qty*price` adds a read-only Eloquent accessor —
no column, not fillable, not in the form, but shown read-only in the list and on the show page and appended
to the model's array/JSON. The expression is a **typed** arithmetic formula (`+ - * / ( )`, numbers, and
other field names) compiled at generation time:

- **numeric** operands (`integer`/`decimal`) use operators — `qty*price` → `($this->qty * $this->price)`.
- **money** operands compose too — `qty * unit_price` (where `unit_price` is `money`) → `$this->unit_price?->multiply($this->qty)`, returning an exact **Money** that's shown formatted (`$7.50`). `money + money` → `->add()`, `money - money` → `->subtract()`, `money / scalar` → `->divide()`.
- nonsensical mixes are rejected at generation (`money * money`, `money + number`, `÷ money`), as are typos, non-numeric references, and anything that isn't a well-formed formula — so nothing user-written becomes arbitrary or broken PHP.

For string concatenation, dates, or formulas the compiler doesn't cover, use a bare `total:computed` and fill
in the generated accessor stub. Computed columns can't be sorted or searched in SQL (there's no column behind
them); the value is appended to every serialization, so make sure its source columns are loaded (a partial
`select()` that omits them makes a numeric formula read them as `0`; money operands are null-safe via `?->`).
Add computed fields at `make` time — `admin-core:field` defers them to the full generator.

**Rollups total up a master-detail document.** `total:rollup:lines.line_total` adds a read-only accessor
that **sums a child hasMany** — the document total = the sum of each line's `line_total`. It's money-aware
(`Ngos\AdminCore\Support\Rollup::sum`): money line totals sum to an exact **Money** (shown formatted), plain
numbers sum numerically, an empty document totals `0`. Like `computed` it's derived — no column, not in the
form, appended to array/JSON, shown read-only in the list and on show — and the rolled-up relation is
**eager-loaded** in the list so it isn't N+1. The relation must be a `hasMany` declared on the same resource
(`lines:hasMany:invoice_lines, total:rollup:lines.line_total`), which completes the master-detail story:
line items (`hasMany`) → per-line money totals (`money` + `computed`) → document total (`rollup`). The summed
attribute can itself be a child `computed`/`money` value. For very large child sets, sum a real column with a
database aggregate instead (the rollup loads the children to sum them).

Two things to know: the rolled-up child value must be **consistently one type** and money rows must **share a
currency** — a mix fails loudly (a silently-wrong money total is worse). And the `hasMany` **child is a
separate resource** this command doesn't scaffold — generate it (`admin-core:make InvoiceLine …`) too, or the
list/show/API will error when the rollup dereferences a missing model (the generator warns you about this).

**Enums are code, not schema.** `status:enum:draft|published` generates `App\Enums\ProductStatus` (a
string-backed PHP enum) as the **single source of truth**: validation uses `Rule::enum`, the model casts
to it, and the form select, index filter-tabs and factory iterate its `cases()`. The DB column stays a
plain `string` — so **adding a value is one new `case` in that file, no migration, and every layer picks
it up**.

`image`/`file` also generate **upload handling in the service** (store on the `public` disk, delete the
old file on update, clean up on delete) and add `enctype="multipart/form-data"` to the form — run
`php artisan storage:link` once. `belongsToMany` generates the pivot migration, a `belongsToMany`
relation, a multi-select, and `sync()` in the service. Both infer the related model/table from the
field name, so generate the related resource first.

**Modifiers** (suffix, any order):

| Modifier | Meaning | What it generates |
|---|---|---|
| `?` | nullable | nullable column + `nullable` rule |
| `^` | unique | unique index + `unique` rule (ignores self by route key on update) |
| `#` | index | plain (non-unique) DB index — `->index()` on a hot filter/sort column (no-op if also `^`, or on a `foreign`, since both already index) |
| `~` | **write-once** | settable on create, **locked on update** — fillable + StoreRequest rule, *no* UpdateRequest rule, `readonly` input on edit |
| `@` | **system** | set by trusted code only — **not** fillable, not validated, not in the form; a `booted()` hook scaffold + nullable column (shown read-only) |

E.g. `slug:string^`, `published_at:date?#` (nullable + indexed), `status:enum:new|paid#`, `sku:string^~` (unique, locked after create).

**Composite unique** — for a constraint spanning **several** columns (one product per order line, one SKU per
branch), the `^` modifier isn't enough. Pass `--unique="col,col"` (repeatable):

```bash
php artisan admin-core:make OrderItem --migration \
  --fields="order_id:foreign:orders, product_id:foreign:products, qty:integer" \
  --unique="order_id,product_id"
```

It generates a DB `$table->unique(['order_id', 'product_id'])` **and** a FormRequest `Rule::unique` (riding on
the group's first column, with a `->where()` for each of the others, ignoring self on update) so a duplicate
combination fails with a clean message before it ever hits the database constraint. Each group needs ≥2
distinct **scalar** columns (string/integer/money/foreign/enum/date/… — not text/json/translatable). A group
that includes a **system** (`@`) or **write-once** (`~`) column is enforced by the DB constraint alone (its
value isn't in the form to validate, so a duplicate surfaces as a database error — the generator warns you).

**Typed system helpers** (imply `@`, auto-filled in the generated `booted()` hook — no TODO to wire up):

| Type | Column | Auto-set to |
|---|---|---|
| `created_by:auth` | nullable `users` FK | `auth()->id()` |
| `code:sku` | nullable string | a generated `Str::upper(Str::random(10))` code |
| `invoice_no:sequence:INV` | nullable, **unique** string | the next **document number** — `"INV-0001"`, `"INV-0002"`, … |

E.g. `--fields="name:string, code:sku, created_by:auth"` gives you an auto SKU and an owner stamp with zero hand-editing — neither is user-fillable.

**Sequence — sequential document numbers.** `invoice_no:sequence:INV` assigns the next number in the model's
`creating` hook: `"INV-0001"`, `"INV-0002"`, … (bare `invoice_no:sequence` → `"0001"`). It's
**concurrency-safe** — `Ngos\AdminCore\Support\Sequence` locks a per-`(key, period)` counter row
(`number_sequences` table, a package-shipped migration — run `php artisan migrate` after upgrading), so two
simultaneous creates never collide. The column is `unique`, and a number you set yourself is kept (the hook
uses `??=`). The number is allocated **inside the create's transaction**, so a rolled-back create releases its
number for the next one — committed rows stay sequential and **gap-free**, with no number burned by a failed
attempt. (For numbering that must never reuse a value even across rolled-back attempts, allocate out-of-band.) Edit the generated hook for other formats — `Sequence::next('invoices.invoice_no', 'INV-', 5, 'year')`
zero-pads to 5 and **restarts each year** (`"INV-2026-00001"`); pass `'month'` to reset monthly.

> Security note: `~` and `@` enforce on the **server** (missing update rule / not fillable), not just the
> readonly input — so a user editing the DOM or POSTing directly still can't change them.

**Foreign keys**: `category_id:foreign` adds a `belongsTo` relation on the model, a Select2 dropdown of
the related rows in the form (labelled by the related row's `name`, falling back to `id`), and a
related-name column in the table. The related table is inferred (`category_id` → `categories`), so it
must already exist — generate the parent resource first.

### App shell (with `--access`)

The `--access` kit now ships a complete admin shell beyond the access screens:

- **Profile / account** (`/admin/profile`) — edit name/email, change password, upload an avatar.
- **Settings** (`/admin/settings`) — grouped key-value app settings with a `Setting::get('key')` helper
  (cached), gated by the `manage-settings` permission. Seeded with `app_name`, `support_email`, etc.
- **Dashboard** — stat-card widgets (Users / Roles / Permissions / Group Permissions counts).
- **Dynamic, permission-aware sidebar** — the menu is a data array in `config('admin-core.menu')`, rendered
  by `<x-admin-core::sidebar-menu />`. Each item is dropped automatically when the user lacks its `can`
  permission or its `route` doesn't exist (so menus for un-installed features vanish on their own), and
  empty section headers are pruned. `admin-core:make` appends the new resource there — no hand-editing
  Blade. (Older installs with the static sidebar still work: the generator falls back to injecting the link.)
- **Database-driven menu + Menu manager** (optional) — manage the sidebar at runtime instead of editing
  config. Set `config('admin-core.menu_source')` to `'database'` (default `'config'`) and the same
  `<x-admin-core::sidebar-menu />` renders the `menu_items` table — cached (`MenuItem::tree()`, busted on
  every write) and filtered by the same permission/route rules. The **Menu manager** at `/admin/menu`
  (System → Menu, `manage-menu`) lets admins add/edit/delete items and **drag to reorder & nest** them; each
  item is a label + icon + a named route *or* custom URL (or none → a section header) + optional permission +
  active toggle. Move your existing menu into the table with **`php artisan admin-core:menu:import`**. Ships
  with `--access`.
- **Multi-portal** — stand up a second portal (merchant, vendor, …) **in one command**:
  `php artisan admin-core:portal merchant` scaffolds its own-guard user model + migration, login + dashboard,
  route group, and menu/permission config. Then `admin-core:make Order --portal=merchant` generates straight
  into it: routes under `routes/Merchant/Modules` with `merchant.*` names, permissions + gates on the
  `merchant` guard, and a `menus.merchant` entry rendered by `<x-admin-core::sidebar-menu menu="merchant"
  guard="merchant" />` (filtered against that portal's user). Single-guard apps just don't use it — nothing
  changes. See UPGRADING for details.
- **Show / detail view** — every resource gets a read-only `show` page + a View button in the table.

### Every list comes with export, import & bulk delete

Generated index screens ship these out of the box:

- **Export** — an `Export` button (a dropdown with a **checkbox per field**) streams the chosen columns to
  CSV (`export` route + `?columns[]=`, gated by `list-*`; leave all checked for everything). Relations are
  included as readable columns — **belongsTo** as the related name (next to the FK) and **belongsToMany** as
  the related names joined (e.g. `tags` = "red, blue"). The output is injection-safe (formula cells are
  neutralised) and leads with a UTF-8 BOM so Excel reads it correctly.
- **Import** — an `Import` button opens a modal to upload a CSV (same shape as Export). The modal links a
  **blank template** (`importTemplate` route) — a header-only CSV of the importable columns (fillable, minus
  password/file columns) so users don't have to guess the fields. Each row is validated against the resource's
  store rules; only fillable columns are kept (so a round-tripped export with `id`/`uuid`/timestamps imports
  cleanly), invalid rows are skipped and reported (`import` route, gated by `create-*`).
- **Bulk delete** — a select-all checkbox column + a "Delete selected" button that soft/hard-deletes the
  chosen rows in one request (`bulkDelete` route, gated by `delete-*`).

All live on the base `WebController` (`export()` / `import()` / `bulkDelete()`), plus a single DataTables
search box (server-side via yajra), so they apply to every resource. Relation columns are searchable by the
**related record's name** out of the box (via `whereHas`): a `belongsTo` column is also sortable (a correlated
subquery), and a `belongsToMany` column is searchable but not sortable (sorting a multi-value relation is
ambiguous). Both assume the related model has a `name` column — the same assumption used to display it.

### Advanced list filters

Beyond the global search box, generated lists get a **filter bar** above the table: a dropdown per `enum`
(its cases), `boolean` (Yes/No) and `foreign` (the related rows) field, a from–to **date range** per
`date`/`datetime`, and a min–max **number range** per `money`/`decimal` (a `money` filter's typed amount is
converted to the stored minor units). A `text` LIKE filter and an `integer` range are supported too — add them
to `listFilters()` by hand (strings are covered by the global search, so they aren't auto-generated; a `text`
value's `%`/`_` act as wildcards, matching the global search). The `foreign` dropdown loads its options at
**render** time (not on each data request), so it suits small/conventional relations — for a large or
translatable-name relation, replace that `listFilters()` entry with a remote source. Changing
a control reloads the table server-side (the shared `datatable.js` appends `?filter[col]=…` to each request);
**Clear** resets them.

It's declared on the controller (the generator fills it from the fields) and **whitelisted** — only the
listed columns are filterable, so a crafted `?filter[…]` can't touch an arbitrary column:

```php
/** @return array<int, array<string, mixed>> */
protected function listFilters(): array
{
    return [
        ['column' => 'status', 'type' => 'select', 'label' => 'Status', 'options' => [...]],
        ['column' => 'created_at', 'type' => 'date', 'label' => 'Created'],
    ];
}
```

`WebController::applyListFilters()` applies them (exact match for `select`, `whereDate` ≥/≤ for `date`) before
yajra's own search/sort/paging run. The `<x-admin-core::list-filters :filters="$acFilters ?? []" table="…_table" />`
component renders the bar (it's in the generated `index` view). Existing installs: republish the package
`datatable.js` (`admin-core:doctor` flags it) and add the component + `listFilters()` to opt a screen in.

**Saved views.** The filter bar also has a per-user **Views** dropdown: save the current filters as a named
view, re-apply one in a click, or delete it. Views are stored **per user, per resource** in a package-shipped
`saved_views` table (run `php artisan migrate` after upgrading); `Route::adminCoreSavedViews()` exposes the
endpoints (`admin-core:install` adds it — existing installs add it to their admin route group). Every row is
scoped to the current user, so one user never sees or deletes another's. The dropdown only appears when those
routes are wired, so it degrades silently if you haven't opted in.

### List footer totals

Turn a list into a **report** with a totals row — `revenue`, on-hand stock value — without leaving the
skeleton. The generator auto-sums every **money** column; the footer total is computed **server-side over the
filtered set** (all pages, honouring the active list filters — not just the visible rows), formatted as exact
Money:

```php
protected function listAggregates(): array
{
    return [
        'total' => ['fn' => 'sum', 'money' => true, 'currency' => null], // money → "៛125,000"
        'qty'   => 'sum',                                                 // plain numeric total
        // fn is one of sum | avg | min | max | count
    ];
}
```

`datatable.js` builds the footer row and fills it from each AJAX response, so the total updates live as filters
change. Notes: a per-record / **multi-currency** money column isn't auto-totalled (mixed currencies can't sum to
one amount); the total reflects the structured list filters, not the free-text search box. Opt in by adding
`listAggregates()` (the generator does it for money columns); a list that declares none has no footer.

### Read-only resources (reports)

A report is a list you read, not edit. `--read-only` scaffolds exactly that — the **list, show and export**, with
all the DataTable filters/totals — but **no create / edit / delete / import**:

```bash
php artisan admin-core:make StockValuation --read-only \
  --fields="variant:string, qty:integer, value:money"
```

It skips the FormRequests and the create/edit/form views, registers only the read routes
(`Route::crud(..., readOnly: true)` → `index`/`getData`/`select` + `show` + `export`), and seeds **only the
`list` permission** — so the create/edit/delete buttons (gated on those absent permissions) never render.
Every write button is also `Route::has()`-guarded, so it stays hidden even with permissions off. Point the
generated model at a real table or a database view; add `listAggregates()` for a totals row. `--soft-deletes`
and `--sortable` (write features) are ignored with a notice. With `--api`, the JSON API is read-only too
(index + show, no store/update/destroy).

Create / update / delete (and restore) flash a `success` message that the layout renders automatically.
Customise or translate it by overriding one method on the generated controller:

```php
protected function message(string $action): string
{
    return __("products.{$action}"); // $action is created|updated|deleted|restored
}
```

### Custom table actions

Beyond delete, every list can carry **custom bulk + per-row actions**. Declare them once in the controller's
`resourceActions()` and the package wires the toolbar button, the row-menu item, the route, the permission
gate, the confirm dialog and the toast:

```php
use Ngos\AdminCore\Actions\Action;

protected function resourceActions(): array
{
    return [
        Action::make('mark-paid')->label('Mark as paid')->icon('bi bi-cash')->color('success')->confirm()
            ->handle(fn ($records) => $records->each->update(['status' => 'paid'])),
    ];
}
```

The handler receives the selected models — resolved **through the resource query**, so scopes / soft-deletes /
tenancy apply (you can only act on rows you can see). Fluent options: `->permission('…')` (defaults to
`{key}-{resource}`), `->withoutPermission()`, `->onlyBulk()`, `->onlyOnRow()`, `->confirm('Sure?')`,
`->success('Done!')`. The permission is enforced **server-side** — hiding a button is cosmetic. Add the
permission (e.g. `mark-paid-product`) to your seeder.

### Field-level permissions

Lock individual fields to a permission — a user without it can't **see** the field (it's disabled in the form)
nor **write** it (stripped server-side, so a crafted POST can't set it either):

```php
protected function fieldPermissions(): array
{
    return ['status' => 'change-status-order', 'cost' => 'edit-cost-product'];
}
```

Covers direct fillable columns. On update the stored value is merged past validation, so locking a *required*
field still lets a restricted user save the rest of the form.

### Approval workflow

Mark a sensitive action `->requiresApproval()` and it won't run for a user who can *request* it but not
*approve* it — instead it files a pending request that an approver clears from the **Approvals inbox**:

```php
Action::make('refund')->requiresApproval()
    ->handle(fn ($records) => $records->each->update(['status' => 'refunded']));
```

- A **requester** (has `refund-order`, not `approve-refund-order`) → files a request; approvers are notified.
- An **approver** opens `admin.approvals.index` → **Approve** (re-runs the action over the captured rows) or
  **Reject** (with a reason); the requester is notified of the decision. A user who *can* approve runs it
  directly (no self-request).

Run `php artisan admin-core:install` (adds `Route::adminCoreApprovals()`) + `php artisan migrate` (the
`approvals` table), and grant `approve-{action}-{resource}` to your approver role.

### Document state machine — transitions + input actions

A document-style resource declares a `transitions()` state machine — each `Transition` is a show-page button
that moves one record between states, **atomically** (lock → verify the `from` state → run the side-effect →
claim the `to` state in one transaction, so a double-click can't post twice), gated by `{key}-{resource}`:

```php
protected function transitions(): array
{
    return [
        Transition::make('post')->from('confirmed')->to('posted')->confirm()
            ->handle(fn ($record) => $record->postToStock()),
    ];
}
```

**Actions that take input.** Add a `form()` and the action collects **validated input** first (the show page
auto-renders a modal); the validated values reach the handler's second argument — so close-with-a-count,
approve-with-a-note, ship-with-a-tracking-number, refund-with-an-amount no longer drop you into a bespoke route:

```php
Transition::make('close')->from('open')->to('closed')
    ->form([
        'closing_counted' => ['required', 'numeric', 'min:0'],          // → number input, required
        'note'            => ['nullable', 'string'],                    // → text input
        'method'          => ['rules' => ['required'], 'type' => 'select', 'options' => ['cash' => 'Cash', 'card' => 'Card']],
    ])
    ->handle(fn ($record, array $input) => app(ShiftService::class)->close($record, $input));
```

The input is validated **before** the lock (an invalid form redirects back with errors and re-opens the modal,
holding no lock). Field types are inferred from the rules (`numeric`→number, `boolean`→checkbox, `date`→date,
else text) or set explicitly (`type` ⇒ `text`/`number`/`textarea`/`select`/`date`/`checkbox`).

**Pure actions (no state move).** Pass `to(null)` (or just omit `to()`) and the action runs its
guarded + validated + atomic handler **without** advancing a state column — a cash pay-in, a recompute. With no
state to claim, the action is kept idempotent by the form's one-time submit token (a double-submit 409s; a
failed run releases the token so a genuine retry still goes through), the same guard the create form uses — so
the auto-rendered modal always carries it. A direct/programmatic POST that omits `_idempotency_key` (or with
`admin-core.forms.idempotency` off) isn't deduped, exactly as for any form:

```php
Transition::make('pay-in')->fromAny()
    ->form(['amount' => ['required', 'numeric', 'min:1'], 'reason' => ['nullable', 'string']])
    ->handle(fn ($record, array $input) => app(ShiftService::class)->payIn($record, $input));
```

A handler written `fn ($record) => …` (one parameter) still works — it just ignores the input. The
`transition` route is wired by `Route::crud()` (skipped on a `--read-only` resource).

### Drag-to-reorder (`--sortable`)

```bash
php artisan admin-core:make Category --sortable --migration --fields="name:string"
```

Adds a `sort` column and a **Sort** toggle button on the index that reveals a **drag-and-drop panel**
(reusing the bundled nestable plugin) — the DataTable stays put. Dragging a row posts the new order to a
`reorder` route, which persists each row's `sort` position via `BaseService::reorder()`. Best paired with
the `--access` kit (which bundles the nestable JS).

### Audit trail (`--audit`)

```bash
php artisan admin-core:make Product --audit --migration --fields="name:string"
```

Adds the package's `LogsActivity` trait to the model, recording every create/update/delete in
`activity_logs` (the actor, the subject, and the changed attributes — sensitive fields like `password`
are filtered out). The `activity_logs` table migration is published by `admin-core:install`; the
`--access` kit adds a read-only **Activity Log** viewer (gated by `list-activity`). Set
`'generator' => ['audit' => true]` to audit every generated resource, or add the trait to any model:

```php
use Ngos\AdminCore\Concerns\LogsActivity;

class Order extends Model { use LogsActivity; }
```

### Error log

The `--access` kit ships an **Error Log**: unhandled exceptions are written to an `error_logs` table
(type, message, `file:line`, stack trace, URL/method, user) and browsable at `admin/error-logs`
(gated by `view-error-log`) with a per-row detail view, delete, and "Clear all". Capture is wired by
a `reportable` callback the package registers on the framework's exception handler — **no
`bootstrap/app.php` edit needed**. It's deliberately quiet: expected exceptions (validation, auth,
404 and other 4xx `HttpException`s) are skipped, only genuine faults (5xx / uncaught) are recorded,
and capture is fully defensive — if the table is missing or anything throws while logging, it no-ops
rather than masking the original error. The `error_logs` migration is published by
`admin-core:install --access`.

The log self-trims: rows older than `config('admin-core.error_log.retention_days')` (default **30**) are
pruned by a daily `model:prune` the package schedules for you (needs the app's scheduler cron running). Set
it to `0` to keep errors forever, or prune on demand with
`php artisan model:prune --model="Ngos\AdminCore\Models\ErrorLog"`.

### Soft deletes & extras

Every `admin-core:make` also generates a **Factory** (field-aware fake data), a **Seeder**, and a
permission-mapped **Policy**. Add `--soft-deletes` for a trash workflow:

```bash
php artisan admin-core:make Product --soft-deletes --migration --fields="name:string, price:decimal?"
```

It adds the `SoftDeletes` trait + `deleted_at` column, a **Trash** button on the index, and a
trash screen with **Restore** / **Delete permanently** (routes `trash` / `restore` / `forceDelete`,
backed by `trashedQuery()` / `restore()` / `forceDelete()` on the base service). "Delete permanently"
is a true **hard delete**; resources generated *without* soft deletes hard-delete on the normal delete.

To make **every** generated resource soft-delete by default, set
`'generator' => ['soft_deletes' => true]` in `config/admin-core.php` (override per-resource with
`--no-soft-deletes` for high-churn tables like sale lines or ledger rows that should hard-delete).

### Generated tests (`--tests`)

```bash
php artisan admin-core:make Product --tests --migration --fields="name:string, price:decimal?"
```

Writes a self-contained `tests/Feature/ProductTest.php` that drives the resource over HTTP: the index +
`getData` render, `store` persists (faking any image/file uploads), `update` + `delete` resolve by the
public route key, and the index is **forbidden** without permission. It creates its own user and grants
the resource's permissions (via `config('admin-core.permission.model')`), so it runs green out of the box
— pair it with `--migration` so `RefreshDatabase` has the table.

### JSON API (`--api`)

For a decoupled front-end (Nuxt, mobile, another SPA) or a multi-tenant merchant portal, `--api` adds a
clean JSON API alongside the Blade admin:

```bash
php artisan admin-core:make Product --api --migration --fields="name:string, price:decimal"
```

Generates a **`ProductResource`** (JsonResource), a **`Api\ProductApiController`** (index/show/store/
update/destroy), and a **`apiResource`** route file under `api.products.*` — Sanctum-gated, with **each
action carrying the same permission as the web admin** (`list`/`create`/`edit`/`delete-product`), so the
API and the back office enforce one permission model.

**Channels are independent — pick what you need, add the rest later:**

```bash
php artisan admin-core:make Product …              # web only (default)
php artisan admin-core:make Product … --api        # web + API
php artisan admin-core:make Product … --api-only   # API only (headless: no views/web routes/sidebar)
```

Re-running is additive (existing files are skipped): a web-only resource gains the API by re-running with
`--api`; an api-only resource gains the web channel by re-running **without** `--api-only`. When you add a
channel to a resource that **already exists**, you can **omit `--fields` entirely** — they're reconstructed
from the existing model + migration (types and all), so adding the API to ten web resources is just:

```bash
for name in Post Product Order Customer …; do
    php artisan admin-core:make "$name" --api      # fields inferred — no retyping
done
```

(Upload `image`/`file` columns can't be told apart from plain strings when inferring — pass `--fields`
explicitly for those.) Both channels share the same model/service/requests, so nothing is duplicated. The controller **reuses the same `Service` +
FormRequests** as the web CRUD, so validation/authorization live in one place; the index is paginated
(`?per_page=`). Crucially, **the public id is always the uuid route key,
never the bigint `id`** — so internal ids are never enumerable across tenants:

```json
{ "data": [ { "id": "019eb7a1-…-c046e429998b", "name": "Espresso", "price": "4.50" } ], "meta": { … } }
```

**List query** — the `index` supports `?search=`, `?sort=`, `?filter[col]=` and `?per_page=`, so a
front-end data table works out of the box:

```
GET /api/products?search=esp&filter[status]=active&sort=-created_at&per_page=20
```

The generated controller derives the **whitelists** from the fields — `$searchable` (text columns,
LIKE), `$sortable` (scalar columns + `created_at`; `-col` = desc), `$filterable` (enum/foreign/boolean,
exact match). Anything not on a whitelist is silently ignored, so a client can't sort/filter by an
arbitrary column. `per_page` is clamped to `config('admin-core.api.max_per_page')` (default 100).

Configure the guard + page size in `config('admin-core.api')` (default `['auth:sanctum']`, 25) — add a
tenant-scoping middleware there for multi-tenant setups. API route files are auto-loaded if `routes/api.php`
globs `routes/Api/Modules/*.php`:

```php
foreach (glob(__DIR__ . '/Api/Modules/*.php') ?: [] as $module) {
    require $module;
}
```

### API auth — token login (`admin-core:install --api-auth`)

Your `--api` resources are guarded, but the SPA/mobile client needs a way to **log in and get a token**.
`admin-core:install --api-auth` scaffolds OAuth2 auth (Laravel Passport, password grant):

```bash
php artisan admin-core:install --api-auth
```

It publishes `Api\AuthController` (`/api/login`, `/api/logout`, `/api/me`) + an `ApiAuthServiceProvider`
(short-lived tokens: 1h access / 14d refresh; login throttled 6/min), wires `routes/api.php` (auth routes
+ the `Api/Modules` loader) and `bootstrap/app.php`, and flips `admin-core.api.middleware` to `auth:api`.
`/api/login` proxies the password grant in-process so the **client secret never leaves the server**:

```jsonc
// POST /api/login {"email":"…","password":"…"}  →
{ "token_type": "Bearer", "access_token": "…", "refresh_token": "…", "expires_in": 3600 }
```

Passport can't be pulled in by an artisan command, so the install prints the finishing steps:
`composer require laravel/passport` → `vendor:publish --tag=passport-migrations` (Passport 12+ no longer
auto-loads them) → `migrate` (oauth tables) → `passport:keys` → `passport:client --password` (put the
id/secret in `.env` as `PASSPORT_PASSWORD_CLIENT_ID`/`_SECRET`) → add the `api` guard (`driver: passport`)
to `config/auth.php` → add `Laravel\Passport\HasApiTokens` to `App\Models\User`. Then `POST /api/login`.

### Non-enumerable URLs — the hybrid key strategy (`--uuid`)

`--uuid` gives a resource a **public UUID** for its URLs while keeping a fast **bigint primary key**:

```bash
php artisan admin-core:make Product --uuid --migration --fields="name:string, category_id:foreign"
```

It generates:
- `$table->id();` — the bigint primary key (all **foreign keys and joins use this** → lean indexes that never bloat)
- `$table->uuid('uuid')->unique();` — the **public** key used in URLs/APIs (`/admin/products/019eadac-…`, non-enumerable)
- `foreignId('category_id')->constrained()` — bigint FK (not `foreignUuid`)
- a model using the package's `HasPublicUuid` trait, which auto-fills the uuid and sets `getRouteKeyName() => 'uuid'`

So you get **non-guessable URLs without the index/join cost of uuid primary keys** — the best default for a system that may grow. The base `BaseService` resolves every action by the model's route key, so edit/show/update/delete/bulk-delete/reorder all use the uuid automatically; plain `id` models (no `--uuid`) keep using `id` unchanged.

To make **every** generated resource hybrid, set `'generator' => ['uuid' => true]` in `config/admin-core.php`
(override per-resource with `--no-uuid`). The `--access` module (users/roles/permissions/group-permissions)
ships hybrid too. Use a plain model? Add `Ngos\AdminCore\Concerns\HasPublicUuid` + a `uuid` column to any model.

> Omitting `--fields` gives the default single `name` column (backward-compatible).
> The generated routes are gated by `permission:*` middleware. Either assign the new permissions to a
> role and wrap the `admin-core:routes` group in `['auth', ...]`, or set `permission.enabled => false`
> in `config/admin-core.php` to browse without auth while developing.

## Adding a field later (`admin-core:field`)

`admin-core:make` scaffolds a resource once; to add a field **afterwards** (the part you'd otherwise do by
hand — migration *and* model *and* views), use `admin-core:field`:

```bash
php artisan admin-core:field Product "sku:string^, discount:decimal?"
php artisan migrate
```

It generates an `add_…_to_products_table` migration and **surgically patches** the model (`$fillable`, casts,
and the `booted()` slug-derive hook), the store/update requests (validation rules + the `prepareForValidation()`
hook for `json`/`password`), the form / table-header / DataTable-script / detail (show) views, and the factory
— adding *just* those fields. Same `--fields` DSL (so `status:enum:a|b` also creates the backed
enum class). **Fields that already exist are detected and skipped** — by the model's `$fillable` *and* the
real DB column (so a column that isn't in `$fillable` is still caught, never producing a duplicate-column
migration). Re-running is safe — pass a mix of old and new and only the new ones are added:

```bash
php artisan admin-core:field Product "status:enum:a|b, paid_at:datetime?"
#   already exists — skipped: status
#   created …_add_paid_at_to_products_table.php  (+ patches)
```

It resolves the resource by **singular** name, so `admin-core:field Products …` and `… Product …` both
hit the `Product` model. If the model doesn't exist — or the table has **no create migration and doesn't
exist** — it refuses up front (so you never get an `add_…` migration that can't run) and tells you to
`admin-core:make … --migration` first.

If the resource has an **`--api`** channel, the new field is also added to its `JsonResource` and the
search/sort/filter whitelists (by type) — so it shows up in the API too, not just the admin.

**Scope:** it handles scalar fields (string/text/number/bool/date/enum/json/slug/password/…). Relation and
upload fields (`foreign`, `belongsToMany`, `image`, `file`) and **system fields** (`@` / `sku` / `auth` —
not mass-assignable, so `$fillable` can't track them for idempotency) need wiring it can't surgically patch
(model relations, the controller's `getData` eager-load, the service's pivot-sync / file-storage, a trusted
value-setter), so it **skips them with a note** — add those by regenerating with `admin-core:make … --force`.

> Patching assumes the views/model still match the generated shape; heavily hand-edited files may need a
> manual touch-up (it never duplicates, so a re-run won't hurt).

## Custom (non-CRUD) page (`admin-core:page`)

`admin-core:make` builds CRUD resources; for a **standalone page** — a Reports screen, a Settings page, a
custom dashboard — use `admin-core:page`:

```bash
php artisan admin-core:page Reports
```

It scaffolds a thin **invokable controller** (`app/Http/Controllers/Backend/ReportsController.php` — fill in
`__invoke()` with whatever data the page needs), a **Blade view** (`resources/views/backend/pages/reports.blade.php`,
already composing `<x-admin-core::page-header>` + `<x-admin-core::card>` + a `<x-admin-core::empty-state>`
placeholder), and a **route** under `routes/Web/Backend/Modules/` (auto-loaded inside the `admin` group →
`admin.reports` at `/admin/reports`). By default it also **adds a sidebar menu entry** and creates a
**`view-reports` permission** granted to the super role — mirroring how `admin-core:make` wires things.
Multi-word names kebab-case (`admin-core:page "Sales Report"` → `admin.sales-report`). Flags: `--no-menu`,
`--no-permission`, `--force`.

Add **`--report`** to scaffold a **data-driven read-only report** instead of a blank page — the controller
hands the view a `$rows` collection, and the view ships the report shell (a count badge, an empty-state, and
a `@foreach` table you fill with columns):

```bash
php artisan admin-core:page "Low Stock" --report
```

## Media library

A browsable, reusable media library. Add a `media` (single) or `gallery` (multiple) field and the generator
wires the whole flow — a picker control, validation, and the save:

```bash
php artisan admin-core:make Product --fields="name:string, cover:media, photos:gallery"
```

These are **relations, not columns**, so one library file can be reused across records. The owning model gets
the `HasMedia` trait:

```php
$product->mediaIn('photos');        // ordered collection of attached files
$product->firstMediaUrl('cover');   // single hero-image url
$product->syncMedia([$ids], 'photos');
```

The library screen (browse / upload / delete) lives at `admin.media.index` — wired by `admin-core:install`
(`Route::adminCoreMedia()`), gated by `manage-media`. Uploads are compressed (WebP) and stored on the
configured disk; deleting a file is refused while it's still attached to a record.

## Dashboard widgets

A config-driven dashboard: drop `<x-admin-core::dashboard />` into your dashboard view and declare the widgets
in `config('admin-core.dashboard.widgets')`. A widget is either a class or an inline array:

```php
'widgets' => [
    \App\Dashboard\RevenueWidget::class,                                 // a class widget
    ['type' => 'stat', 'title' => 'Users', 'icon' => 'bi-people',        // an inline widget
     'value' => fn ($c) => \App\Models\User::query()->count(), 'link' => '/admin/users'],
    ['type' => 'chart', 'title' => 'Signups', 'col' => 6,
     'chart' => fn ($c) => ['type' => 'line', 'series' => [/* … */], 'categories' => [/* … */]]],
    ['type' => 'list', 'title' => 'Latest orders',
     'rows' => fn ($c) => [['label' => '…', 'meta' => '…', 'link' => '…']]],
],
```

Scaffold a class widget with `php artisan admin-core:make-widget Revenue --type=stat` (or `chart` / `list`) —
it extends `StatWidget` / `ChartWidget` / `ListWidget`. The `$c` (DashboardContext) carries the active date
range: `$c->scope($query)` filters to it, `$c->scopePrevious($query)` to the previous period (for trends). A
date-range toolbar and per-user drag-reorder/hide (saved per user) are on by default
(`dashboard.date_filter` / `dashboard.customizable`). Wire it with `Route::adminCoreDashboard()` (added by
`admin-core:install`).

## Multi-portal

Need a second admin area — a **merchant** or **vendor** portal with its own login, separate from your
staff admin? One command scaffolds the whole thing:

```bash
php artisan admin-core:portal merchant
php artisan migrate
php artisan db:seed --class=MerchantSeeder   # creates merchant@example.com / password + a full-access role
```

You get, all on a separate `merchant` auth guard (its own users, its own login):

- `App\Models\Merchant` (guard-scoped) + migration, a login + dashboard, and a factory + seeder;
- a `merchant` route group at `/merchant/*` (login, dashboard, and its own module folder);
- the guard + provider in `config/auth.php`, and the menu + super-role entries in `config/admin-core.php`.

Log in at **`/merchant/login`**. Then generate resources straight **into** the portal:

```bash
php artisan admin-core:make Order --portal=merchant
php artisan db:seed --class=MerchantSeeder   # re-run to grant the new resource's permissions
```

`--portal=merchant` routes everything to that portal — routes under `/merchant` with `merchant.*` names,
permissions on the `merchant` guard, and the link added to the merchant sidebar. Add more portals by
changing the name; single-guard apps never touch any of this.

> **One guard, not separate logins?** If admin and merchant are the *same* users with different roles, skip
> `--portal`/`--guard` entirely and just give each area a named menu — see
> [`config('admin-core.menus')`](#ui-components--theme).

## Notifications

`--access` installs an in-app notification system on Laravel's database notifications: a **bell** in the top
bar (`<x-admin-core::notifications-bell />`) with an unread badge and a recent-list dropdown, a full
**notifications page** at `/admin/notifications`, and mark-read / mark-all-read / delete.

**Send one in a single line** — no notification class to write — with the bundled `AdminNotification`:

```php
use Ngos\AdminCore\Notifications\AdminNotification;

$user->notify(new AdminNotification(
    title:   'Order shipped',
    message: "Order #{$order->id} is on its way.",
    url:     route('admin.orders.show', $order), // followed when the row is clicked
    icon:    'bi-truck',                          // any Bootstrap icon (optional)
    extra:   ['order_id' => $order->id],          // optional extra payload keys
));
```

Need mail/broadcast/queued, or richer logic? Write your own `Notification` instead — the UI only needs
`toArray()` to return `title` / `message` / `url` / `icon`:

```php
public function via($notifiable): array { return ['database']; }

public function toArray($notifiable): array
{
    return ['title' => 'Order shipped', 'message' => '…', 'url' => '…', 'icon' => 'bi-truck'];
}
```

The bell renders only where the routes exist (`Route::adminCoreNotifications()`, added to the admin group by
`--access`) and the user is `Notifiable` — so it's safe everywhere. **Existing installs:** re-run
`php artisan admin-core:install --access` to add the table, route and bell, then `php artisan migrate`.

### Realtime (live bell)

By default the bell is **pull-based** (updates on page load). Turn on **realtime** and each `AdminNotification`
also **broadcasts**, so the bell's badge bumps live and a toast pops on arrival — no refresh. It's **opt-in**
because it needs a broadcaster + Laravel Echo + a queue worker:

1. **Enable it:** `ADMIN_CORE_REALTIME=true` (or `config('admin-core.notifications.realtime')`). Per-notification
   override: `new AdminNotification(..., broadcast: true)`.
2. **A broadcaster** — [Reverb](https://laravel.com/docs/reverb) (first-party, self-hosted) is easiest:
   `composer require laravel/reverb && php artisan reverb:install`, then run `php artisan reverb:start`. (Pusher
   works too — the kit's `echo.js` supports both.)
3. **Front-end env** (read at build time, then `npm run build`):
   ```dotenv
   VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
   VITE_REVERB_HOST="${REVERB_HOST}"
   VITE_REVERB_PORT="${REVERB_PORT}"
   VITE_REVERB_SCHEME="${REVERB_SCHEME}"
   ```
   Echo + pusher-js are **lazy-loaded only when a key is set** — with realtime off they're not in the bundle.
4. **Channel auth** — notifications broadcast on the private channel `App.Models.User.{id}`. Fresh Laravel apps
   already authorize this in `routes/channels.php`; if yours doesn't:
   ```php
   Broadcast::channel('App.Models.User.{id}', fn ($user, $id) => (int) $user->id === (int) $id);
   ```
5. **Run a queue worker** (`php artisan queue:work`) — broadcasts are queued.

That's it: `$user->notify(new AdminNotification(...))` now lands live. The kit listens on the user's channel
(`resources/js/realtime.js`) and updates the bell; the dropdown list itself refreshes on the next open/load.

## Translation & multi-language

Two features, both **middleware-based** (no public translate endpoint to secure) and **multi-language** —
list every language you offer in `config('admin-core.translation.locales')`:

```php
'translation' => [
    'driver'  => env('ADMIN_CORE_TRANSLATOR', 'mymemory'), // mymemory | libretranslate | null
    'locales' => ['en' => 'English', 'km' => 'ខ្មែរ', 'th' => 'ไทย'], // add as many as you like
    'default' => 'en',
],
```

**1. Per-user UI language.** Each user gets their own language — one admin in English, another in Khmer.
Drop the switcher in your topbar:

```blade
<x-admin-core::language-switcher />
```

Each item is a plain `?setlang=km` link the `SetLocale` middleware picks up, applies with `App::setLocale()`,
and remembers — persisted to a `users.locale` column (a migration for it **ships with `--access`**, so per-user
language is durable across devices out of the box; without the column it falls back to the session). The
middleware auto-registers on the `web` group, so no route changes are needed. Use the shipped strings via
`__('admin-core::admin-core.actions.save')`, etc. (publish/extend with `--tag=admin-core-lang`; for a no-access
install, add the column yourself: `$table->string('locale', 8)->nullable();`).

**2. Content auto-translate (bidirectional).** For multilingual *data* (e.g. a product name per language),
render a translatable input:

```blade
<x-admin-core::translatable-input name="name" label="Name" :value="old('name', $product->name ?? [])" />
```

It shows one box per locale (`name[en]`, `name[km]`, …) plus a hidden marker. On **save**, the `AutoTranslate`
middleware takes whichever language you filled and **fills the empty ones for you** — type Khmer, get English
(and Thai, …); or type English, get the rest. It runs inside the authenticated, CSRF-protected submit, never
overwrites what you typed, and caps outbound calls per request. (Store the values however you like — e.g. a
JSON-cast attribute or a translatable package.)

**Auto-generate a language file.** Instead of translating the UI strings by hand, machine-translate them:

```bash
php artisan admin-core:translate th        # writes lang/vendor/admin-core/th/admin-core.php via the driver
php artisan admin-core:translate vi --force # re-translate everything (default: keep existing keys)
```

Then add the code to `translation.locales` and review the output (machine translation is a draft).

**Drivers & privacy.** `mymemory` is free and needs no key. For privacy, point `libretranslate.url` at a
self-hosted instance so text never leaves your servers. `null` disables auto-translate (UI language still
works). All drivers are **fail-safe** — if the provider is down, the original text is kept, so a save never
breaks. API keys live in `.env`, server-side only. Machine output is a *draft to review*, especially for Khmer.

## Lifecycle commands

```bash
php artisan admin-core:version                  # show the installed package version
php artisan admin-core:uninstall                # un-wire (remove the route/middleware blocks + User trait)
php artisan admin-core:uninstall --purge        # also delete the files it published
php artisan admin-core:reinstall [--access]     # purge + reinstall (clean re-scaffold)
```

Everything `install` injects is wrapped in `// >>> admin-core:* … // <<< admin-core:*` sentinels, so
`uninstall` removes **exactly** what it added. **Your `admin-core:make`-generated resources are never
touched** — only package-owned files (config, layout, access module, front-end kit) are purged. Add
`--force` to skip the confirmation prompt.

## UI components & theme

The `--access` kit ships a custom Bootstrap-5 theme (no AdminLTE) plus reusable Blade components:

- **`<x-admin-core::page-header title="…" description="…">`** — breadcrumb + title + description, with
  an `<x-slot:actions>` for the primary button. For sub-pages, add `parent` + `:parent-url` for a
  `Dashboard › Posts › Edit` trail. Used on every index / create / edit / show.
- **`<x-admin-core::filter-tabs table="#x_table" :column="2" :tabs="['' => 'All', 'draft' => 'Draft']" />`**
  — segmented tabs that drive a server-side DataTables column search (auto-added for enum fields).
- **`<x-admin-core::data-table id="products_table" thead="…partials.thead">`** — the list-page shell: a card
  with an `<x-slot:toolbar>` (export / import / bulk-delete), the `<table>` your DataTable binds to, and a
  default slot under it (e.g. the sort panel). Every generated index uses it.
- **`<x-admin-core::export-menu :route="route('admin.products.export')" :fields="['name' => 'Name', …]" />`**
  — the CSV export dropdown with a per-column checkbox picker (all checked = everything).
- **`<x-admin-core::import-modal :route="route('admin.products.import')" :template="…" title="Products" />`**
  — the “Import CSV” button + modal (file upload, optional blank-template link). Gate it with `@can(...)`.
- **`<x-admin-core::form-row name="price" label="Price">…control…</x-admin-core::form-row>`** — one labelled
  horizontal field row with the validation-error message wired; the generated forms emit one per field.
- **`<x-admin-core::editor name="description" label="…" :value="old('description', $object?->description)" min-height="250px" />`**
  — a CKEditor 5 rich-text field (loaded from CDN, paste-from-Word cleanup); `min-height` sets the editable
  area height (CKEditor 5 otherwise collapses to ~1 line). Generated forms use it for rich `text` fields.
- **`<x-admin-core::repeater name="units" :rows="old('units', $rows)" row="…partials.unit-row" add-label="Add unit" />`**
  — repeatable rows for a master-detail form (a variant's units, an order's line items). You supply a **row
  partial** that renders one row's inputs named with the `:index` it's given (e.g. `name="units[{{ '$index' }}][unit_id]"`),
  wrapping each row in `[data-ac-repeater-row]` with a `[data-ac-repeater-remove]` button. The component
  renders it once per existing row, plus a hidden `<template>` the Add button clones with a fresh unique
  index. Add/remove is inline JS (no build step); it posts `name[i][...]` arrays (indexes need not be
  sequential — re-index server-side).
- **`<x-admin-core::page-loader />`** — a thin top progress bar shown during full-page navigation; drop it
  once in the layout. Pairs with the pre-paint sidebar-state script for a flash-free refresh.
- **`<x-admin-core::status :value="$object->status" />`** — the soft `.ac-status` pill for an enum value
  (accepts a backed-enum instance or a string; blank renders nothing). Used in the table and show view.
  Semantic colours for common words: published/active → green, pending → amber, failed/cancelled → red,
  archived → muted; unknown values fall back to neutral.
- **`<x-admin-core::stat-list title="Summary" :items="[['label' => 'Refund', 'value' => '-35.00', 'suffix' => 'USD']]" />`**
  — a label→value summary card (right-aligned tabular numbers, negatives in red, `'strong' => true` for totals).
- **`<x-admin-core::stat-card label="Users" :count="$n" icon="bi-people" :route="route('…')" tone="1" />`**
  — a dashboard KPI card (big number + label + icon, optionally a link; `tone` 1-4 picks the accent). The
  dashboard composes these.
- **`<x-admin-core::card>`** — a Bootstrap card with optional `<x-slot:header>` / `<x-slot:footer>`; the body
  is wrapped in `card-body` (pass `:body-class="''"` to drop the wrapper, e.g. a flush table). Used by the
  generated show/create/edit and the dashboard panels.
- **`<x-admin-core::form-actions submit="Create" :cancel="route('…index')" />`** — the submit + cancel row at
  the foot of a form (pass `:submit-class="config('class.button.update')"` on edit). Every generated and
  access-module form uses it.
- **`<x-admin-core::alert type="warning" dismissible>…</x-admin-core::alert>`** — an inline contextual message
  with a leading icon (info/success/warning/danger; `error` → danger). For page-level messages; one-off flash
  is still handled by the layout.
- **`<x-admin-core::modal id="editX" title="Edit" size="lg">… <x-slot:footer>…</x-slot></x-admin-core::modal>`**
  — a reusable Bootstrap modal shell (title/body/footer slots); trigger from any `data-bs-target="#editX"`.
- **`<x-admin-core::empty-state icon="bi-inbox" title="Nothing yet" message="…"><x-slot:action>…</x-slot></x-admin-core::empty-state>`**
  — a centered placeholder (icon + title + message + optional CTA) for empty lists/sections.
- **`<x-admin-core::skeleton :lines="3" />`** / `type="card"` / `type="table" :rows="5" :cols="4"` — animated
  loading-skeleton placeholders to show while content loads, then swap for the real thing (shimmer is
  dark-mode aware).
- **`<x-admin-core::tabs :tabs="['profile' => 'Profile', 'security' => 'Security']">`** with a
  `<x-admin-core::tab-pane id="profile" active>…</x-admin-core::tab-pane>` per id — Bootstrap content tabs for
  multi-section pages/forms (`:pills="true"` for the pill style). Distinct from `filter-tabs` (DataTable column
  search).
- **`<x-admin-core::avatar :src="$user->avatar_url" :name="$user->name" size="40" />`** — a round photo, or a
  stable colour + initials circle when there's no image.
- **`<x-admin-core::badge tone="danger" pill>3</x-admin-core::badge>`** — a small count/label badge
  (`tone` → Bootstrap `text-bg-*`). For an enum status pill use `status` instead.
- **Customize drawer** (palette icon in the topbar): theme (light/dark/system), accent colour, density,
  layout (sidebar/top-nav), container (fluid/boxed) and direction (LTR/RTL) — persisted in `localStorage`.
- **Row actions** render as a kebab (⋯) menu (View / Edit / Delete). Add your own items — an "Approve"
  button, a "Change password" link — via the 3rd arg of `actions()` in the generated controller's
  `getData()`. Each item is `['label' => …, 'url' => …]` plus optional `icon` / `can` (a permission that
  gates it) / `class`; they render above Edit/Delete:

  ```php
  ->addColumn('actions', fn ($row) => $this->actions($row, 'order', [
      ['label' => 'Approve', 'url' => route('admin.orders.approve', $row->getRouteKey()),
       'icon' => 'bi bi-check2-circle', 'can' => 'edit-order'],
  ]))
  ```

Re-skin the whole thing from the `--ac-*` CSS tokens / SCSS variables at the top of `resources/sass/app.scss`.

## Customising

- **Stubs:** `php artisan vendor:publish --tag=admin-core-stubs` → `stubs/admin-core/` (yours win over the package's).
- **DataTable partials:** `php artisan vendor:publish --tag=admin-core-views` → `resources/views/vendor/admin-core/`.
- **Config:** edit `config/admin-core.php`.
- **Uploads (compression + CDN):** all image/file uploads go through `Ngos\AdminCore\Support\Media`. Set
  `uploads.compress`/`max_width`/`quality` to control WebP compression, `uploads.disk` to store on any
  filesystem (e.g. s3 + CloudFront for a CDN), and `uploads.cdn_url` to prepend a CDN base URL when building
  image URLs. All in `config/admin-core.php` (`ADMIN_CORE_UPLOAD_DISK` / `ADMIN_CORE_CDN_URL`).
- **Base model for generated models:** set `generator.base_model` in `config/admin-core.php` and every
  `admin-core:make` model `extends` it. Share common behaviour by `use`-ing traits in your base (keep the
  logic in traits so `Role`/`Permission` — which must extend Spatie's classes — can `use` them too):
  ```php
  // app/Models/BaseModel.php
  abstract class BaseModel extends \Illuminate\Database\Eloquent\Model
  {
      use \Ngos\AdminCore\Concerns\HasPublicUuid;   // shared behaviour lives in traits
      protected $casts = ['published_at' => 'datetime'];
  }
  // config/admin-core.php → 'generator' => ['base_model' => \App\Models\BaseModel::class],
  ```

## Using the core directly

```php
// routes/Web/Backend/Modules/products.php
Route::group(['prefix' => 'products', 'as' => 'products.'], function () {
    Route::crud('product', \App\Http\Controllers\Backend\ProductController::class);
});
```

```php
class ProductController extends \Ngos\AdminCore\Http\Controllers\WebController { /* $service, $viewPath, $routeBase, $storeRequest, $updateRequest */ }
class ProductService    extends \Ngos\AdminCore\Services\BaseService          { /* $model */ }
```

The web and API controllers share a common spine, so generated controllers stay thin and cross-cutting
concerns have one home:

```
BaseController  (service + FormRequest bindings; your shared seam)
├── WebController  (web: views, redirects, DataTables, export/import)  ← thin web controllers
└── ApiController   (JSON: index/show/store/update/destroy, paginated)  ← thin --api controllers
```

`BaseService` is the service-layer equivalent: it holds the model binding + the foundational `query()`, and
`find()` flows through it — so a single `query()` override (e.g. a tenant scope) covers every list, lookup,
update and delete across both the admin and the API.

For **master-detail** forms (a parent + repeater line items), `BaseService::syncHasMany()` reconciles the
posted rows — update by `id`, create the new ones, delete the rest (`null` leaves the relation untouched).
Pass an `$attributes` callback to whitelist/derive per-row columns (return `null` to skip a blank row):

```php
public function create(array $data): Model
{
    $items = $data['items'] ?? null; unset($data['items']);
    $purchase = parent::create($data);
    $this->syncHasMany($purchase, 'items', $items, fn ($r) => empty($r['product_id']) ? null : [
        'product_id' => $r['product_id'],
        'qty'        => $r['qty'] ?? 0,
    ]);

    return $purchase;
}
```

Generated resources with a `hasMany` field wire this automatically.

## Testing

The package ships a Pest + Orchestra Testbench suite (in-memory SQLite):

```bash
composer install
composer test       # the full suite
composer analyse    # Larastan / PHPStan level 5
```

It covers the `FieldSet` generator (every field type, UUID, soft-deletes, uploads, m2m, factory), the
`Route::crud` macro (registration + permission gating), the `WebController` flow
(store/validate/update/delete/getData/bulk-delete/export), settings, soft-delete trash/restore, and the
two commands end to end: `admin-core:make` (scaffolds valid, token-free, `php -l`-clean files whose
migration actually runs) and `admin-core:install` (config/view publishing + the `routes/web.php` /
`bootstrap/app.php` wiring, including idempotency). Dedicated regression guards cover the hybrid-key
edit/show route links (uuid, not bigint id), enum status pills, segmented filter tabs, the consistent
create/edit/show page-header, and the group-permission uuid fill. CI runs both `test` and `analyse` on
PHP 8.3 + 8.4.

## License

MIT
