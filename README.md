# ngos/admin-core

A reusable, config-driven **admin CRUD core** for Laravel 13 + AdminLTE 4 / Bootstrap 5.

It gives you a thin, conventional CRUD skeleton — abstract `CrudController` + `CrudService`, a
`Route::crud()` route macro, and an `admin-core:make` resource generator — so every backend table
in your app is built the same way, with permission gating and yajra DataTables wired in.

- **Blade + Bootstrap 5 + jQuery DataTables.** No Livewire. jQuery only for plugins.
- **Config-driven.** Route-name prefix, view-path prefix, permission pattern and pagination all in `config/admin-core.php`.
- **Permission-aware.** Each CRUD action is gated by `permission:{action}-{resource}` (spatie/laravel-permission).

## Requirements

- PHP ^8.3
- Laravel ^13
- `spatie/laravel-permission` ^8, `yajra/laravel-datatables-oracle` ^13 (pulled in automatically)

## Installation

```bash
composer require ngos/admin-core
php artisan admin-core:install
```

`admin-core:install` scaffolds the host-side glue the generated pages depend on (idempotent — safe to re-run, `--force` to overwrite):

| Published | Purpose |
|---|---|
| `config/admin-core.php` | route/view/permission/pagination conventions |
| `config/class.php` | CSS-class map for tables/buttons/icons |
| `resources/views/backend/layouts/app.blade.php` | self-contained CDN starter layout (jQuery, DataTables, Bootstrap 5, SweetAlert2, toastr, CSRF) |
| `resources/views/backend/dashboard.blade.php` | minimal dashboard so `admin.dashboard` resolves |
| `routes/Web/Backend/Modules/` | auto-loaded folder for generated resource routes |
| `routes/web.php` | an `admin` route group + module loader (added once, marked `admin-core:routes`) |

Then finish the spatie setup:

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

### Full access module (login + users/roles/permissions)

Want a working authenticated admin out of the box? Pass `--access`:

```bash
php artisan admin-core:install --access
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\AccessSeeder
```

This additionally scaffolds (all in your `App\` namespace, yours to edit):

- **Auth** — a minimal session `LoginController` + login view + `/login` `/logout` routes; the `admin` route group is wrapped in `auth`.
- **Users / Roles / Permissions** management screens (controllers, services, form requests, Blade views) built on the CRUD core, with role/permission assignment.
- `App\Models\Role` / `App\Models\Permission` (extending spatie), the `HasRoles` trait added to `App\Models\User`, sidebar links, and an `AccessSeeder` that creates an `admin` role with every permission plus an admin user.

Log in at `/login` with **`admin@example.com` / `password`**. (`admin-core:make` auto-grants each new resource's permissions to the `admin` role, so there's nothing to re-seed.)

Prefer a single command? `admin-core:install --access --build --seed` also runs `npm install && npm run build` and migrates + seeds the admin user for you.

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
| `email` | `string` | email | `email` |
| `enum:a\|b\|c` | `string` | `<select>` | `in:a,b,c` |
| `foreign` (`x_id`) | `foreignId()->constrained()` | Select2 of related rows | `exists:xs,id` |
| `image` | `string` (path) | file input + preview | `image,max:2048` |
| `file` | `string` (path) | file input | `file,max:10240` |
| `belongsToMany` (`m2m`) | pivot table | multi-Select2 | `array` + `exists` |

`image`/`file` also generate **upload handling in the service** (store on the `public` disk, delete the
old file on update, clean up on delete) and add `enctype="multipart/form-data"` to the form — run
`php artisan storage:link` once. `belongsToMany` generates the pivot migration, a `belongsToMany`
relation, a multi-select, and `sync()` in the service. Both infer the related model/table from the
field name, so generate the related resource first.

**Modifiers** (suffix, any order): `?` = nullable, `^` = unique.
E.g. `slug:string^`, `published_at:date?`.

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
- **Auto-sidebar** — `admin-core:make` injects the new resource's nav link automatically (idempotent),
  so you never hand-edit the sidebar.
- **Show / detail view** — every resource gets a read-only `show` page + a View button in the table.

### Every list comes with export & bulk delete

Generated index screens ship two things out of the box:

- **Export** — an `Export` button streams the table to CSV (`export` route, gated by `list-*`).
- **Bulk delete** — a select-all checkbox column + a "Delete selected" button that soft/hard-deletes the
  chosen rows in one request (`bulkDelete` route, gated by `delete-*`).

Both live on the base `CrudController` (`export()` / `bulkDelete()`), plus a single DataTables search box
(server-side via yajra), so they apply to every resource.

### Drag-to-reorder (`--sortable`)

```bash
php artisan admin-core:make Category --sortable --migration --fields="name:string"
```

Adds a `sort` column and a **Sort** toggle button on the index that reveals a **drag-and-drop panel**
(reusing the bundled nestable plugin) — the DataTable stays put. Dragging a row posts the new order to a
`reorder` route, which persists each row's `sort` position via `CrudService::reorder()`. Best paired with
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

### UUID primary keys

Give a resource a UUID key (and UUID foreign/pivot keys) with `--uuid`:

```bash
php artisan admin-core:make Product --uuid --migration --fields="name:string, category_id:foreign"
```

It generates `$table->uuid('id')->primary()`, `foreignUuid(...)`, and a `HasUuids` model. To make
**every** generated resource use UUIDs, set `'generator' => ['uuid' => true]` in `config/admin-core.php`
(override per-resource with `--no-uuid`). The core controller/service accept `int|string` keys, so
integer and UUID resources coexist.

> Omitting `--fields` gives the default single `name` column (backward-compatible).
> The generated routes are gated by `permission:*` middleware. Either assign the new permissions to a
> role and wrap the `admin-core:routes` group in `['auth', ...]`, or set `permission.enabled => false`
> in `config/admin-core.php` to browse without auth while developing.

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
class ProductController extends \Ngos\AdminCore\Http\Controllers\CrudController { /* $service, $viewPath, $routeBase, $storeRequest, $updateRequest */ }
class ProductService    extends \Ngos\AdminCore\Services\CrudService          { /* $model */ }
```

## Testing

The package ships a Pest + Orchestra Testbench suite (in-memory SQLite):

```bash
composer install
composer test       # the full suite
composer analyse    # Larastan / PHPStan level 5
```

It covers the `FieldSet` generator (every field type, UUID, soft-deletes, uploads, m2m, factory), the
`Route::crud` macro (registration + permission gating), the `CrudController` flow
(store/validate/update/delete/getData/bulk-delete/export), settings, soft-delete trash/restore, and the
two commands end to end: `admin-core:make` (scaffolds valid, token-free, `php -l`-clean files whose
migration actually runs) and `admin-core:install` (config/view publishing + the `routes/web.php` /
`bootstrap/app.php` wiring, including idempotency). CI runs both `test` and `analyse` on PHP 8.3 + 8.4.

## License

MIT
