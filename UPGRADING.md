# Upgrading

`ngos/admin-core` follows semver-ish tags. Most upgrades are `composer update` + a
re-build of the front-end. The notes below cover the releases that need a manual step.
See `CHANGELOG.md` for the full per-version list.

After any release that touches the theme or JS, rebuild the front-end:

```bash
npm install && npm run build
```

---

## → v2.5.0 — One-command portals (`admin-core:portal`)

No breaking change — new command. Stand up a whole second portal in one go:

```bash
php artisan admin-core:portal merchant
php artisan migrate
php artisan admin-core:make Order --portal=merchant
```

It scaffolds the guard-scoped `Merchant` model + migration, a login + dashboard, a portal layout with the
merchant menu, and wires `config/auth.php` (guard + provider), `routes/web.php` (the `merchant` route group
globbing `routes/Merchant/Modules`), and `config/admin-core.php` (the `menus.merchant` + super-role config).
Then log in at `/merchant/login`. **Note:** the config wiring needs the current published config — if
`config/admin-core.php` predates v2.2, the command tells you to re-publish (or add the menu by hand). For
full sidebar styling, publish the admin-core theme (`npm run build`); the scaffold uses Bootstrap via CDN.

## → v2.4.0 — One-flag portal resources (`--portal`)

No breaking change. Prefer `--portal` over juggling `--menu` + `--guard`: it also mounts the routes in the
portal, which `--guard` alone did not.

```bash
php artisan admin-core:make Order --portal=merchant
```

Generates the route module into `routes/Merchant/Modules/` with `merchant.order.*` route-names, sets the
controller's `routePrefix` to `merchant.`, adds the item to `config('admin-core.menus.merchant')`, and gates
on the `merchant` guard. The portal's route group (see `admin-core:portal`, or wire it yourself) must glob
`routes/Merchant/Modules/*.php` inside a `->name('merchant.')->prefix('merchant')` group. Plain admin
resources are unchanged — omit `--portal`.

## → v2.3.0 — Guard-aware permissions (separate-guard portals)

No breaking change — opt in per resource with `--guard`. For a portal on its **own** auth guard (separate
user table, e.g. a `merchant` guard), generate with:

```bash
php artisan admin-core:make Order --menu=merchant --guard=merchant
```

That creates the permissions with `guard_name = merchant`, gates the generated routes on the merchant guard
(`Route::crud('order', …, 'merchant')` + `permission:list-order,merchant`), and grants them to a merchant-guard
super role. Tell the package that role per guard:

```php
// config/admin-core.php → permission
'guard'  => 'web',                                    // default guard
'guards' => ['merchant' => ['super_role' => 'merchant-admin']],
```

Mount the generated route module under your merchant route group (`auth:merchant`), and render its menu with
`<x-admin-core::sidebar-menu menu="merchant" guard="merchant" />`. **Single-guard apps**: omit `--guard` —
behaviour is unchanged.

## → v2.2.0 — Multi-portal menus (named menus + guard-aware)

No breaking change — additive. To give a second portal (merchant, vendor, …) its own sidebar:

```php
// config/admin-core.php
'menus' => [
    'merchant' => [
        ['label' => 'Storefront', 'route' => 'merchant.dashboard', 'icon' => 'bi bi-shop', 'match' => 'merchant'],
        // admin-core:menu:merchant      ← admin-core:make --menu=merchant appends here
    ],
],
```

```blade
{{-- the merchant portal's layout --}}
<ul class="ac-nav"><x-admin-core::sidebar-menu menu="merchant" guard="merchant" /></ul>
```

Pass `guard` when the portal authenticates on a **separate guard** (its own user table), so the menu's
`can` checks ask the right user. If both portals share one guard (admin/merchant are just permission sets),
omit `guard`. Generate into a portal's menu with `php artisan admin-core:make Order --menu=merchant`.

## → v2.1.0 — Dynamic, permission-aware sidebar menu

No breaking change — opt in. The sidebar can now be driven by a `menu` array in `config/admin-core.php`
and rendered by `<x-admin-core::sidebar-menu />`, which hides items the user can't reach (by `can`
permission or a missing `route`). `admin-core:make` appends new resources to that array instead of editing
Blade; if the config has no `menu` (older installs), it falls back to the previous Blade injection — so you
don't have to do anything.

**To adopt the dynamic menu:**

```bash
php artisan vendor:publish --tag=admin-core-config --force   # gets the `menu` array (re-apply any local config tweaks)
php artisan vendor:publish --tag=admin-core-views --force    # optional: the component-based sidebar partial
```

Then the sidebar partial is just:

```blade
<ul class="ac-nav">
    <x-admin-core::sidebar-menu />
</ul>
```

Each item: `['label' => 'Products', 'route' => 'admin.products.index', 'icon' => 'bi bi-box', 'can' =>
'list-product', 'match' => 'admin/products*']`. Use `['header' => 'Section']` for a label and a `children`
array for a collapsible group. Run `php artisan config:clear` after generating if you cache config.

## → v2.0.0 — Deprecated base-class aliases removed

**Breaking — the only breaking change in 2.0.** The back-compat aliases `CrudController` and `CrudService`
(deprecated since v1.19.0) are gone. If any of your classes still `extends` them, switch to the real bases:

```php
- class ProductController extends \Ngos\AdminCore\Http\Controllers\CrudController
+ class ProductController extends \Ngos\AdminCore\Http\Controllers\WebController

- class ProductService extends \Ngos\AdminCore\Services\CrudService
+ class ProductService extends \Ngos\AdminCore\Services\BaseService
```

Find them with: `grep -rn "CrudController\|CrudService" app/`. Code generated by `admin-core:make` on
v1.19.0+ already uses `WebController` / `BaseService`, so it needs no change. Nothing else changed —
config, routes, generated output, and every command behave exactly as in v1.28.x.

---

## → v1.28.0 — `#` index modifier

No breaking change. Suffix a field with `#` to add a plain DB index on its column:

```bash
php artisan admin-core:make Order --migration --fields="status:enum:new|paid#, placed_at:datetime#"
php artisan admin-core:field Order "tracking_no:string#"
```

No-op when the column is already `^` unique or a `foreign` (both already index). To index a column on a
resource generated earlier, add `->index()` in a hand-written `Schema::table(...)` migration as usual.

## → v1.26.0 — Add a channel without re-typing `--fields`

No breaking change. When you add the API (or web) channel to a resource that **already exists**, you can now
omit `--fields` — they're inferred from the model + migration:

```bash
php artisan admin-core:make Post --api      # was: --api --fields="title:string, status:enum:…"
```

Explicit `--fields` still takes precedence. Upload (`image`/`file`) columns look like plain strings when
inferred, so pass `--fields` for resources that have them.

## → v1.24.0 — Add fields to an existing resource

No breaking change — new `admin-core:field` command. Instead of hand-editing the migration + model + views
to add a field, run:

```bash
php artisan admin-core:field Product "sku:string^, discount:decimal?"
php artisan migrate
```

Existing fields are skipped (idempotent). Publish the stubs (`--tag=admin-core-stubs`) to customise the
generated `add_…` migration.

## → v1.23.0 — API auth scaffold

No breaking change — opt in with `admin-core:install --api-auth`. It scaffolds the Passport OAuth2 auth
endpoints and wires routing/config, then prints the Passport setup it can't run for you:

```bash
php artisan admin-core:install --api-auth
composer require laravel/passport
php artisan migrate && php artisan passport:keys
php artisan passport:client --password --name="API" --provider=users   # → .env PASSPORT_PASSWORD_CLIENT_ID/_SECRET
# add the 'api' guard (driver: passport) to config/auth.php, and HasApiTokens to App\Models\User
```

## → v1.22.0 — Channel-selective generation

No breaking change. `admin-core:make` gains `--api-only` (headless API, no web files). Existing
resources can adopt a channel additively — re-run the same command with the desired flags and the same
`--fields`; files that already exist are skipped, only the missing channel is created:

```bash
php artisan admin-core:make Product --api        # add API to a web resource (fields inferred since v1.26)
php artisan admin-core:make Product              # add web to an api-only resource
```

## → v1.21.0 — Backed enums for enum fields

No breaking change — resources generated earlier keep their `in:` rules and string handling. Newly
generated enum fields get an `App\Enums\{Model}{Field}` backed enum as the single source of truth
(validation `Rule::enum`, model cast, form select, filter-tabs, factory). **Add a value by adding a
`case` to that file — no migration** (the column is a plain string).

To migrate an existing resource by hand: create the enum class, cast the attribute to it, swap the
`in:` rule for `Rule::enum(...)`, and append `->value` wherever the attribute is rendered (the cast
makes it an enum instance, not a string).

## → v1.20.1 — API permission gating

The generated API routes now permission-gate **every** action (previously only `store`/`update` were
gated, via their FormRequest). If you generated `--api` resources on an earlier version, regenerate the
route file or add the gate yourself:

```php
$gate = fn (string $action) => config('admin-core.permission.enabled')
    ? 'permission:' . $action . '-product' : [];
Route::get('/',       [...,'index'])  ->name('index')  ->middleware($gate('list'));
// store→create, show→list, update→edit, destroy→delete
```

Make sure the API token's user actually holds the resource permissions, or the API will (correctly) 403.

## → v1.20.0 — API list query

No action required for new `--api` resources (the `$searchable` / `$sortable` / `$filterable` whitelists are
generated). To enable it on an API controller generated earlier, add the properties:

```php
protected array $searchable = ['name'];          // ?search= (LIKE)
protected array $sortable   = ['name', 'created_at']; // ?sort=col / ?sort=-col
protected array $filterable = ['status'];         // ?filter[col]=value
```

`ApiController::index` reads them; columns not whitelisted are ignored. Optionally set
`config('admin-core.api.max_per_page')` (default 100).

## → v1.19.0 — Base classes renamed (`WebController` / `BaseService`)

No action required — `CrudController` and `CrudService` live on as **deprecated aliases**, so existing code
keeps working. To adopt the new names, change your `extends`:

```php
- class ProductController extends \Ngos\AdminCore\Http\Controllers\CrudController
+ class ProductController extends \Ngos\AdminCore\Http\Controllers\WebController

- class ProductService extends \Ngos\AdminCore\Services\CrudService
+ class ProductService extends \Ngos\AdminCore\Services\BaseService
```

Re-publish the stubs (`vendor:publish --tag=admin-core-stubs --force`) to get the new names in future
generated code. The aliases will be dropped in the next major version.

## → v1.17.0 — JSON API

No breaking change — opt in with `--api`:

```bash
php artisan admin-core:make Product --api --migration --fields="name:string, price:decimal"
```

One-time setup: load the generated API route files by globbing them from `routes/api.php`, and
(optionally) publish the config to tune `admin-core.api.middleware` / `per_page`:

```php
// routes/api.php
foreach (glob(__DIR__ . '/Api/Modules/*.php') ?: [] as $module) {
    require $module;
}
```

Requires `laravel/sanctum` (the default guard). For multi-tenant, add your tenant-scoping middleware to
`config('admin-core.api.middleware')`.

## → v1.16.0 — Generated tests

No breaking change — opt in with `--tests`:

```bash
php artisan admin-core:make Product --tests --migration --fields="name:string"
```

The generated `tests/Feature/ProductTest.php` is self-contained (creates a permissioned user, exercises
the CRUD cycle over HTTP) and assumes a `User` factory + Spatie permissions, which the `--access` kit
provides. Pair with `--migration` so `RefreshDatabase` has the table.

## → v1.15.0 — CSV import

No breaking change for new resources (the Import button + `import` route are generated). To add import to
a resource generated on an older version, register the route alongside the others:

```php
Route::post('import', 'import')->name('import')
    ->middleware(config('admin-core.permission.enabled') ? 'permission:create-posts' : []);
```

then drop an Import button/modal posting to `route('admin.posts.import')` on the index (see the current
`index` stub). `import()` already lives on the base `CrudController`, so no controller change is needed.

## → v1.14.0 — More field types

No breaking change. The `--fields` DSL gains `time`, `url`, `slug`, `json`, and `password`. Examples:

```bash
php artisan admin-core:make Article --migration --fields="\
  name:string, slug:slug, website:url, start_at:time, meta:json, secret:password"
```

`slug` is auto-derived from `name` when blank; `json` round-trips through a textarea (array cast);
`password` is hashed and a blank value on edit keeps the current one. Existing resources are unaffected.

## → v1.13.0 — Model casts

No breaking change. Newly generated models declare a `casts()` method for boolean / date / datetime /
decimal columns, so those attributes come back as `bool` / `Carbon` / fixed-precision strings instead of
raw DB values. To add it to an existing model:

```php
protected function casts(): array
{
    return [
        'is_active'    => 'boolean',
        'published_at' => 'datetime',
        'price'        => 'decimal:2',
    ];
}
```

## → v1.12.0 — Hybrid-key edit fix + page-header on create/edit/show

**Action required if you generated resources before v1.12.0 *and* use `--uuid` (hybrid keys).**
Older generated `edit.blade.php` / `show.blade.php` keyed their route links by the bigint
`id` instead of the public route key, so saving an edit resolved `uuid = <int>` and crashed
with an invalid-uuid SQL error. Patch the two links in each generated resource:

```blade
{{-- edit.blade.php — form action --}}
- <form action="{{ route('admin.posts.update', $object->id) }}" method="POST">
+ <form action="{{ route('admin.posts.update', $object->getRouteKey()) }}" method="POST">

{{-- show.blade.php — Edit link --}}
- <a href="{{ route('admin.posts.edit', $object->id) }}" ...>
+ <a href="{{ route('admin.posts.edit', $object->getRouteKey()) }}" ...>
```

Also new: `create` / `edit` / `show` now use `<x-admin-core::page-header>` (matching the index),
and the component gained optional `parent` + `parentUrl` props for a sub-page crumb
(`Dashboard › Posts › Edit`):

```blade
<x-admin-core::page-header title="Edit Post" parent="Posts"
    :parent-url="route('admin.posts.index')" />
```

## → v1.11.0 — Enum status pills

No breaking change. Newly generated resources render their **enum** column as a soft
`.ac-status` pill (semantic colours for common status words, neutral fallback) in both the
table and the show view. To apply it to an existing column, wrap the value:

```blade
<span class="ac-status" data-status="{{ $object->status }}">{{ $object->status }}</span>
```

In a DataTables cell, do it in `editColumn` and add the column to `rawColumns`.

## → v1.9.0 — Segmented filter tabs

No breaking change. Newly generated resources with an **enum** field get
`<x-admin-core::filter-tabs>` on their index automatically. To add tabs to an existing
list, drop the component above the table:

```blade
<x-admin-core::filter-tabs table="#posts_table" :column="2"
    :tabs="['' => 'All', 'draft' => 'Draft', 'published' => 'Published']" />
```

`column` is the DataTable column index (the leading checkbox is `0`); each array key is
the value searched on that column (empty = clear), the value is the label.

## → v1.8.0 — Page-header component

Generated and `--access` list pages now use `<x-admin-core::page-header>` instead of a
`@section('breadcrumb')` block. Existing hand-written pages keep working; to adopt the new
header, replace the breadcrumb section with:

```blade
<x-admin-core::page-header title="Posts" description="Manage your posts.">
    <x-slot:actions>
        <a href="{{ route('admin.posts.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Add Post
        </a>
    </x-slot:actions>
</x-admin-core::page-header>
```

Resource-specific row actions now go **inside** the kebab (⋯) menu rather than as separate
buttons — pass them to `actions()`:

```php
->addColumn('actions', fn ($row) => $this->actions($row, 'post', [
    ['label' => 'Publish', 'url' => route(...), 'icon' => 'bi bi-send', 'can' => 'edit-post'],
]))
```

## → v1.5.0 — Custom theme (AdminLTE removed)

The front-end no longer depends on `admin-lte`. Re-publish the themed assets and rebuild:

```bash
php artisan admin-core:install --access --force
npm install && npm run build
```

Custom row links in your own views must use the model's route key, not the raw id:
`route('admin.x.edit', $row->getRouteKey())`.

## → v1.3.0 — Hybrid keys (bigint PK + public uuid)

`--uuid` / `generator.uuid` now generate a **bigint `id` primary key plus a unique public
`uuid` column** used in URLs (instead of a uuid primary key). Foreign/pivot keys are always
`foreignId` (bigint).

**Breaking:** resources previously generated with a uuid primary key should be regenerated
(or keep their own migrations). Models use the `HasPublicUuid` trait, which sets
`getRouteKeyName() => 'uuid'`; `CrudService` resolves every action by the route key, so all
edit/show/update/delete/bulk links must pass `getRouteKey()` — **never** `->id`. Operations
scoped to the current user should use `auth()->user()` directly, not `find(auth()->id())`.
