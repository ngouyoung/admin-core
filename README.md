# ngos/admin-core

Build a full Laravel admin panel fast. **One command scaffolds a CRUD resource** — model, migration,
controller, form requests, Blade views, permissions and a searchable/sortable/exportable DataTable — on a
clean, branded **Bootstrap 5** theme.

- **One generator.** `admin-core:make Product` → a complete, permission-gated admin screen.
- **Batteries included** (all opt-in flags): login + users/roles/permissions, CSV import/export, soft-deletes,
  audit log, a JSON API, and a dynamic, permission-aware sidebar.
- **Multi-portal.** Stand up a second portal (merchant, vendor…) on its own auth guard in one command.
- **Thin & conventional.** Generated code lives in *your* `App\` namespace and extends a small base
  (`WebController` + `BaseService`) — no magic, easy to read and edit.

## Contents

- [Quickstart](#quickstart) — a working admin + your first resource
- [Installation](#installation) (minimal vs `--access`)
- [Generating a resource](#generating-a-resource) → [field types](#generating-fields-too---fields) ·
  [add a field later](#adding-a-field-later-admin-corefield)
- What every list gets: [export / import / bulk-delete](#every-list-comes-with-export-import--bulk-delete) ·
  [reorder](#drag-to-reorder---sortable) · [soft-deletes](#soft-deletes--extras) · [audit](#audit-trail---audit)
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
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
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
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan admin-core:install --access --build --seed   # or drop --build/--seed to do them yourself
```

On top of the minimal install, `--access` adds (all in *your* `App\` namespace, yours to edit):

- **Auth** — a session `LoginController` + login view + `/login` `/logout`; the `admin` route group is `auth`-gated.
- **Users / Roles / Permissions** screens built on the CRUD core, with role/permission assignment.
- `App\Models\Role` / `App\Models\Permission` (extending spatie), `HasRoles` on `App\Models\User`, the sidebar,
  and an `AccessSeeder` (an `admin` role with every permission + the `admin@example.com` user).
- The themed front-end kit (`--build` runs `npm install && npm run build`).

`admin-core:make` auto-grants each new resource's permissions to the `admin` role, so there's nothing to re-seed.

## Generating a resource

```bash
php artisan admin-core:make Product --migration
```

Generates the model, service, controller, form requests, a route module, the Blade views, and the
`list/create/edit/delete-product` permissions. Visit `/admin/products`.

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
| `integer` | `integer` | number | `integer` |
| `decimal` | `decimal(10,2)` | number (step) | `numeric` |
| `boolean` | `boolean` default 0 | checkbox | `boolean` |
| `date` / `datetime` | `date` / `dateTime` | date / datetime-local | `date` |
| `time` | `time` | time | `date_format:H:i` |
| `email` | `string` | email | `email` |
| `url` | `string` | url | `url,max:255` |
| `enum:a\|b\|c` | `string` | `<select>` from cases | `Rule::enum` (generated backed enum) |
| `slug` | `string` nullable unique | text | `alpha_dash` + unique (auto from `name`) |
| `json` | `json` | monospace textarea | `array` (decoded from the textarea) |
| `password` | `string` | password | `min:8` (hashed; blank on edit = keep) |
| `foreign` (`x_id`) | `foreignId()->constrained()` | Select2 of related rows | `exists:xs,id` |
| `image` | `string` (path) | file input + preview | `image,max:2048` |
| `file` | `string` (path) | file input | `file,max:10240` |
| `belongsToMany` (`m2m`) | pivot table | multi-Select2 | `array` + `exists` |

The model also gets a `casts()` method (`boolean`, `date`, `datetime`, `decimal:2`, `json → array`,
`password → hashed`, `enum → its backed enum class`). A `slug` left blank is derived from `name` in the
`creating` hook; a `json` field round-trips through a textarea (decoded in `prepareForValidation`, stored
via the array cast); a blank `password` on **update** is dropped so the existing hash is preserved.

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

**Typed system helpers** (imply `@`, auto-filled in the generated `booted()` hook — no TODO to wire up):

| Type | Column | Auto-set to |
|---|---|---|
| `created_by:auth` | nullable `users` FK | `auth()->id()` |
| `code:sku` | nullable string | a generated `Str::upper(Str::random(10))` code |

E.g. `--fields="name:string, code:sku, created_by:auth"` gives you an auto SKU and an owner stamp with zero hand-editing — neither is user-fillable.

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

- **Export** — an `Export` button streams the table to CSV (`export` route, gated by `list-*`). The output
  is injection-safe (formula cells are neutralised) and leads with a UTF-8 BOM so Excel reads it correctly.
- **Import** — an `Import` button opens a modal to upload a CSV (same shape as Export). Each row is
  validated against the resource's store rules; only fillable columns are kept (so a round-tripped export
  with `id`/`uuid`/timestamps imports cleanly), invalid rows are skipped and reported (`import` route,
  gated by `create-*`).
- **Bulk delete** — a select-all checkbox column + a "Delete selected" button that soft/hard-deletes the
  chosen rows in one request (`bulkDelete` route, gated by `delete-*`).

All live on the base `WebController` (`export()` / `import()` / `bulkDelete()`), plus a single DataTables
search box (server-side via yajra), so they apply to every resource. Relation columns are searchable by the
**related record's name** out of the box (via `whereHas`): a `belongsTo` column is also sortable (a correlated
subquery), and a `belongsToMany` column is searchable but not sortable (sorting a multi-value relation is
ambiguous). Both assume the related model has a `name` column — the same assumption used to display it.

Create / update / delete (and restore) flash a `success` message that the layout renders automatically.
Customise or translate it by overriding one method on the generated controller:

```php
protected function message(string $action): string
{
    return __("products.{$action}"); // $action is created|updated|deleted|restored
}
```

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

### Soft deletes & extras

Every `admin-core:make` also generates a **Factory** (field-aware fake data), a **Seeder**, and a
permission-mapped **Policy**. Add `--soft-deletes` for a trash workflow:

```bash
php artisan admin-core:make Product --soft-deletes --migration --fields="name:string, price:decimal?"
```

It adds the `SoftDeletes` trait + `deleted_at` column, a **Trash** button on the index, and a
trash screen with **Restore** / **Delete permanently** (routes `trash` / `restore` / `forceDelete`,
backed by `trashedQuery()` / `restore()` / `forceDelete()` on the base service).

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
`composer require laravel/passport` → `passport:keys` → `passport:client --password` (put the id/secret in
`.env` as `PASSPORT_PASSWORD_CLIENT_ID`/`_SECRET`) → add the `api` guard (`driver: passport`) to
`config/auth.php` → add `Laravel\Passport\HasApiTokens` to `App\Models\User`. Then `POST /api/login`.

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
- **Status pills** — enum columns render as a soft `.ac-status` pill in the table and the show view
  (semantic colours for common words: published/active → green, pending → amber, failed/cancelled → red,
  archived → muted; unknown values fall back to neutral). Reuse anywhere with
  `<span class="ac-status" data-status="…">…</span>`.
- **`<x-admin-core::stat-list title="Summary" :items="[['label' => 'Refund', 'value' => '-35.00', 'suffix' => 'USD']]" />`**
  — a label→value summary card (right-aligned tabular numbers, negatives in red, `'strong' => true` for totals).
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
