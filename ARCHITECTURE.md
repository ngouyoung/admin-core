# Architecture

A one-page map of `ngos/admin-core` — the reusable skeleton you start a project from. The idea in one
line: **business logic + validation live once (a service + FormRequests); the web admin and the JSON API
are two thin presentation skins over that shared core.**

```
                       Request (web form / JSON)
                                  │
        ┌─────────────────────────┴──────────────────────────┐
        ▼                                                     ▼
  CrudController (web)                                  ApiController (api)
  views · redirects · DataTables · export/import       JsonResource · pagination
        └──────────────┬───────────────┬───────────────────┘
                       │ BaseController │   (shared: $service + $storeRequest/$updateRequest)
                       └───────┬────────┘
                               ▼
                         CrudService          ← writes: create / update / delete / restore / reorder
                               │ BaseService   ← reads:  $model + query()  (the tenant-scope seam)
                               ▼
                            Eloquent model  (HasPublicUuid: bigint id + public uuid)
```

## Layers

| Class | Layer | Responsibility |
|---|---|---|
| `BaseController` | controller seam | shared bindings (`$service`, `$storeRequest`, `$updateRequest`); the place for cross-cutting web+API concerns |
| `CrudController` | web | `index/create/store/show/edit/update/delete` (views + redirects), `getData` (DataTables), `export`/`import`/`bulkDelete` |
| `ApiController` | api | `index/show/store/update/destroy` as JSON — paginated, addressed by the uuid route key |
| `BaseService` | read foundation | `$model` + `query()` — override `query()` once to scope every read (incl. `find`) |
| `CrudService` | writes | `find/create/update/delete` + soft-delete (`trashedQuery/restore/forceDelete`) + `reorder` |

The web and API controllers **call the same `CrudService`** and are validated by the **same FormRequests**.
They diverge only at presentation (HTML vs JSON), which is inherent.

## Request lifecycle

- **Web write:** `CrudController::store()` → `app($storeRequest)->validated()` → `CrudService::create()`
  inside `DB::transaction()` → redirect to index.
- **API write:** `ApiController::store()` → same FormRequest, same `CrudService::create()` → `201` +
  `JsonResource`.
- **Read:** both go through `CrudService::query()` / `find()` → so one `query()` override scopes both.

## Where do I put X?

| You want to… | Put it in… |
|---|---|
| change a validation rule | the generated `Store…Request` / `Update…Request` |
| add business logic (side effects, calculations) | the resource's `…Service` (override `create`/`update`) |
| scope everything to a tenant / a user | override `query()` in a host base service (extend `CrudService`) |
| change what the API returns | the `…Resource` (JsonResource) |
| add a row action / column | the controller's `getData()` / the `thead` partial |
| wrap every API response (envelope) / add auth | `BaseController` (or a host base over `ApiController`) |
| re-skin the admin | the `--ac-*` SCSS tokens, or publish the views |
| change generated output | publish the stubs (`vendor:publish --tag=admin-core-stubs`) |

## What the generator emits per resource

`admin-core:make Product --uuid --migration --api --tests` →

```
app/Models/Product.php                         extends Model (HasPublicUuid, casts)
app/Services/Products/ProductService.php       extends CrudService
app/Http/Controllers/Backend/ProductController extends CrudController   (thin)
app/Http/Controllers/Api/ProductApiController  extends ApiController    (thin, --api)
app/Http/Resources/ProductResource.php         JsonResource             (--api)
app/Http/Requests/Product/{Store,Update}…      FormRequest
app/Policies/ProductPolicy.php                 permission-mapped
resources/views/backend/pages/products/…       index/create/edit/show/form/partials
routes/Web/Backend/Modules/products.php        Route::crud + export/import/bulkDelete
routes/Api/Modules/products.php                Sanctum apiResource      (--api)
database/{factories,seeders,migrations}/…      + tests/Feature/ProductTest.php (--tests)
```

Everything generated is **thin** — the logic lives in the five base classes above.

## Conventions

- **Hybrid keys** (`HasPublicUuid`): fast bigint `id` for joins/FKs, public `uuid` as the route key — the
  uuid is the only identifier exposed in URLs and API payloads (non-enumerable across tenants).
- **Permissions**: `Route::crud` gates each action with `permission:{action}-{resource}`; the generator
  creates the rows and grants them to `config('admin-core.permission.super_role')`.
- **Config-driven**: route name prefix, view path prefix, permission model, API guard/page size — all in
  `config/admin-core.php`. No project specifics are hardcoded in the package.
- **Override, don't fork**: stubs are publishable, views are publishable, `query()`/`BaseController` are
  override points.

See `README.md` for usage and `UPGRADING.md` for per-version migration notes.
