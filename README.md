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

## Generating a resource

```bash
php artisan admin-core:make Product --migration
```

Generates the model, service, controller, form requests, a route module, the Blade views, and the
`list/create/edit/delete-product` permissions. Visit `/admin/products`.

> The generated routes are gated by `permission:*` middleware. Either assign the new permissions to a
> role and wrap the `admin-core:routes` group in `['auth', ...]`, or set `permission.enabled => false`
> in `config/admin-core.php` to browse without auth while developing.

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

## License

MIT
