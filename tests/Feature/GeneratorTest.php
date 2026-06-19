<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/*
 * Exercises the `admin-core:make` generator end to end against the Testbench
 * skeleton app: runs the real command, asserts the generated files exist, are
 * token-free, are valid PHP, and (for --migration) actually migrate. Every file
 * created is removed in before/after hooks so the skeleton stays clean.
 */

/** Every file/dir `admin-core:make Gizmo …` can create. */
function gizmoTargets(): array
{
    return [
        app_path('Models/Gizmo.php'),
        app_path('Services/Gizmos'),
        app_path('Http/Controllers/Backend/GizmoController.php'),
        app_path('Http/Requests/Gizmo'),
        app_path('Policies/GizmoPolicy.php'),
        base_path('routes/Web/Backend/Modules/gizmos.php'),
        resource_path('views/backend/pages/gizmos'),
        database_path('factories/GizmoFactory.php'),
        database_path('seeders/GizmoSeeder.php'),
        base_path('tests/Feature/GizmoTest.php'),
        app_path('Http/Resources/GizmoResource.php'),
        app_path('Http/Controllers/Api/GizmoApiController.php'),
        base_path('routes/Api/Modules/gizmos.php'),
        app_path('Enums'), // generated backed enums (GizmoStatus, …)
    ];
}

function cleanupGizmo(): void
{
    foreach (gizmoTargets() as $path) {
        File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);
    }
    foreach (glob(database_path('migrations/*_gizmos_table.php')) ?: [] as $migration) {
        File::delete($migration); // create_ and add_…_to_gizmos_table
    }
}

/** Every concrete generated file currently on disk (php + blade). */
function gizmoFiles(): array
{
    $files = [];
    foreach (gizmoTargets() as $path) {
        if (File::isDirectory($path)) {
            $files = array_merge($files, array_map(fn ($f) => $f->getPathname(), File::allFiles($path)));
        } elseif (File::exists($path)) {
            $files[] = $path;
        }
    }

    return array_merge($files, glob(database_path('migrations/*_create_gizmos_table.php')) ?: []);
}

beforeEach(fn () => cleanupGizmo());
afterEach(fn () => cleanupGizmo());

it('scaffolds a full resource with valid, token-free PHP', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, price:decimal?, body:text?',
        '--migration' => true,
    ])->assertSuccessful();

    // Core files all landed.
    expect(File::exists(app_path('Models/Gizmo.php')))->toBeTrue()
        ->and(File::exists(app_path('Services/Gizmos/GizmoService.php')))->toBeTrue()
        ->and(File::exists(app_path('Http/Controllers/Backend/GizmoController.php')))->toBeTrue()
        ->and(File::exists(app_path('Http/Requests/Gizmo/StoreGizmoRequest.php')))->toBeTrue()
        ->and(File::exists(app_path('Http/Requests/Gizmo/UpdateGizmoRequest.php')))->toBeTrue()
        ->and(File::exists(database_path('factories/GizmoFactory.php')))->toBeTrue()
        ->and(File::exists(resource_path('views/backend/pages/gizmos/index.blade.php')))->toBeTrue()
        ->and(glob(database_path('migrations/*_create_gizmos_table.php')))->toHaveCount(1);

    $files = gizmoFiles();
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = File::get($file);

        // No stub token survived replacement (guards the strtr token bugs).
        expect($contents)->not->toContain('Dummy', "leftover Dummy token in {$file}")
            ->and($contents)->not->toContain('__AC_', "leftover __AC_ token in {$file}");

        // Generated PHP must be syntactically valid.
        if (str_ends_with($file, '.php')) {
            $lint = Process::run('php -l ' . escapeshellarg($file));
            expect($lint->successful())->toBeTrue("php -l failed for {$file}:\n" . $lint->output());
        }
    }

    // Sanity: model/migration carry the requested fields.
    expect(File::get(app_path('Models/Gizmo.php')))->toContain('class Gizmo')->toContain("'name'")
        ->and(File::get(app_path('Http/Controllers/Backend/GizmoController.php')))->toContain('class GizmoController');

    $migration = File::get(glob(database_path('migrations/*_create_gizmos_table.php'))[0]);
    expect($migration)->toContain("Schema::create('gizmos'")->toContain("'name'")->toContain("'price'");

    // A non-portal resource renders in the admin layout.
    expect(File::get(resource_path('views/backend/pages/gizmos/index.blade.php')))
        ->toContain("@extends('backend.layouts.app')");

    // The request defers authorization to the route's permission middleware (guard-correct on web + API);
    // re-checking with can() here would resolve on the API auth guard and wrongly 403 a web admin's token.
    expect(File::get(app_path('Http/Requests/Gizmo/StoreGizmoRequest.php')))
        ->toContain('public function authorize(): bool')
        ->toContain('return true;')
        ->not->toContain("\$this->user()->can('create-gizmo')")
        ->and(File::get(app_path('Http/Requests/Gizmo/UpdateGizmoRequest.php')))
        ->toContain('return true;')
        ->not->toContain("\$this->user()->can('edit-gizmo')");
});

it('generates a migration that actually runs', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, price:decimal?, body:text?',
        '--migration' => true,
    ])->assertSuccessful();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('gizmos'))->toBeTrue()
        ->and(Schema::hasColumns('gizmos', ['id', 'name', 'price', 'body']))->toBeTrue();
});

it('uses the hybrid key strategy with --uuid (bigint PK + public uuid + bigint FKs)', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, category_id:foreign',
        '--uuid' => true,
        '--migration' => true,
    ])->assertSuccessful();

    $migration = File::get(glob(database_path('migrations/*_create_gizmos_table.php'))[0]);
    expect($migration)
        ->toContain('$table->id();')                 // fast bigint primary key
        ->toContain("\$table->uuid('uuid')->unique();") // public URL/API key
        ->not->toContain("uuid('id')")                // NOT a uuid primary key
        ->toContain('foreignId')                      // lean bigint foreign key
        ->not->toContain('foreignUuid');

    expect(File::get(app_path('Models/Gizmo.php')))
        ->toContain('HasPublicUuid')
        ->toContain('Ngos\AdminCore\Concerns\HasPublicUuid');
});

it('handles write-once (~) and system (@) field modifiers', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, sku:string^~, secret:string@',
        '--migration' => true,
    ])->assertSuccessful();

    $model = File::get(app_path('Models/Gizmo.php'));
    $store = File::get(app_path('Http/Requests/Gizmo/StoreGizmoRequest.php'));
    $update = File::get(app_path('Http/Requests/Gizmo/UpdateGizmoRequest.php'));
    $form = File::get(resource_path('views/backend/pages/gizmos/partials/form.blade.php'));
    $migration = File::get(glob(database_path('migrations/*_create_gizmos_table.php'))[0]);

    // write-once `sku`: fillable + store rule, but NO update rule, and readonly on edit.
    expect($model)->toContain("'sku'")
        ->and($store)->toContain("'sku'")
        ->and($update)->not->toContain("'sku'")
        ->and($form)->toContain('readonly');

    // system `secret`: NOT fillable, NOT in either request, NOT in the form, but a column +
    // a booted() hook scaffold; column is nullable so the scaffold runs.
    expect($model)->not->toContain("'secret'")            // not in $fillable
        ->and($model)->toContain('protected static function booted')
        ->and($model)->toContain('$model->secret = null')
        ->and($store)->not->toContain("'secret'")
        ->and($update)->not->toContain("'secret'")
        ->and($migration)->toContain("\$table->string('secret')->nullable();");

    // generated PHP still valid.
    foreach ([app_path('Models/Gizmo.php'), $store === '' ? null : app_path('Http/Requests/Gizmo/StoreGizmoRequest.php')] as $php) {
        if ($php) {
            expect(Process::run('php -l ' . escapeshellarg($php))->successful())->toBeTrue();
        }
    }
});

it('auto-fills :auth and :sku system fields in the booted hook', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, created_by:auth, code:sku',
        '--migration' => true,
    ])->assertSuccessful();

    $model = File::get(app_path('Models/Gizmo.php'));
    $migration = File::get(glob(database_path('migrations/*_create_gizmos_table.php'))[0]);

    // auth → set from auth()->id(); sku → generated string; both NOT fillable.
    expect($model)
        ->toContain('$model->created_by = auth()->id();')
        ->toContain('$model->code = \\Illuminate\\Support\\Str::upper')
        ->not->toContain("'created_by'")
        ->not->toContain("'code'");

    // auth → users FK (bigint, nullable); sku → nullable string column.
    expect($migration)
        ->toContain("\$table->foreignId('created_by')->nullable()->constrained('users')")
        ->toContain("\$table->string('code')->nullable();");

    expect(Process::run('php -l ' . escapeshellarg(app_path('Models/Gizmo.php')))->successful())->toBeTrue();
});

it('files permissions under a group permission that receives a uuid (hybrid keys)', function () {
    // Reproduce a hybrid-key install: a group_permissions table whose uuid column
    // is NOT NULL. createPermissions() inserts the group via the query builder,
    // which bypasses the model's HasPublicUuid hook — it must fill the uuid itself.
    config()->set('admin-core.permission.enabled', true);
    config()->set('admin-core.permission.model', \Ngos\AdminCore\Tests\Fixtures\HybridPermission::class);

    Schema::dropIfExists('group_permissions');
    Schema::dropIfExists('permissions');
    Schema::create('permissions', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name')->default('web');
        $t->unsignedBigInteger('group_id')->nullable();
        $t->timestamps();
    });
    Schema::create('group_permissions', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->id();
        $t->uuid('uuid')->unique();
        $t->string('name');
        $t->unsignedBigInteger('parent_id')->nullable();
        $t->integer('sort')->default(0);
        $t->timestamps();
    });
    \Illuminate\Support\Facades\DB::table('group_permissions')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'All', 'sort' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string'])
        ->assertSuccessful();

    $group = \Illuminate\Support\Facades\DB::table('group_permissions')->where('name', 'Gizmos Management')->first();
    expect($group)->not->toBeNull()
        ->and($group->uuid)->not->toBeNull()
        ->and(\Illuminate\Support\Facades\DB::table('permissions')->where('name', 'list-gizmo')->value('group_id'))->toEqual($group->id);
});

it('adds segmented filter tabs for an enum field', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, status:enum:draft|published|archived',
        '--migration' => true,
    ])->assertSuccessful();

    // status is the 2nd field, so column index 2 (checkbox is 0, name is 1).
    // Tabs come from the backed enum's cases — single source of truth.
    expect(File::get(resource_path('views/backend/pages/gizmos/index.blade.php')))
        ->toContain('<x-admin-core::filter-tabs')
        ->toContain('table="#gizmos_table"')
        ->toContain(':column="2"')
        ->toContain(':enum="\App\Enums\GizmoStatus::class"');
});

it('composes the index from the reusable UI components (not hand-rolled markup)', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, price:decimal',
        '--migration' => true,
    ])->assertSuccessful();

    // The card/toolbar/table shell, the export dropdown and the import modal are now components — the export
    // field-picker is passed as a value => label literal rather than baked-in checkbox HTML.
    expect(File::get(resource_path('views/backend/pages/gizmos/index.blade.php')))
        ->toContain('<x-admin-core::data-table id="gizmos_table" thead="backend.pages.gizmos.partials.thead">')
        ->toContain('<x-admin-core::export-menu :route="route(\'admin.gizmos.export\')"')
        ->toContain("'name' => 'Name', 'price' => 'Price'")
        ->toContain('<x-admin-core::import-modal :route="route(\'admin.gizmos.import\')"')
        ->not->toContain('class="card-header')   // the shell is the component's job now
        ->not->toContain('id="importModal"');    // ditto the modal
});

it('generates a backed enum as the single source of truth for an enum field', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, status:enum:draft|published|on_hold',
        '--migration' => true,
    ])->assertSuccessful();

    // The enum class itself, one case per value (studly name, raw value).
    expect(File::get(app_path('Enums/GizmoStatus.php')))
        ->toContain('enum GizmoStatus: string')
        ->toContain("case Draft = 'draft';")
        ->toContain("case Published = 'published';")
        ->toContain("case OnHold = 'on_hold';");

    // Validation via Rule::enum, not a hardcoded in: list.
    expect(File::get(app_path('Http/Requests/Gizmo/StoreGizmoRequest.php')))
        ->toContain('Rule::enum(\App\Enums\GizmoStatus::class)')
        ->not->toContain("'in:draft");

    // Model casts the column to the enum (DB column stays a plain string).
    expect(File::get(app_path('Models/Gizmo.php')))
        ->toContain("'status' => \App\Enums\GizmoStatus::class");
    $migration = collect(glob(database_path('migrations/*_create_gizmos_table.php')))->first();
    expect(File::get($migration))->toContain("\$table->string('status')");

    // Form select + factory iterate the cases — no duplicated value lists anywhere.
    expect(File::get(resource_path('views/backend/pages/gizmos/partials/form.blade.php')))
        ->toContain('\App\Enums\GizmoStatus::cases()');
    expect(File::get(database_path('factories/GizmoFactory.php')))
        ->toContain('fake()->randomElement(\App\Enums\GizmoStatus::cases())');
});

it('renders an enum column as a status pill (table + show), marked raw', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, status:enum:draft|published|archived',
        '--migration' => true,
    ])->assertSuccessful();

    // DataTables cell: editColumn wraps the value in an .ac-status pill, and the
    // column is registered raw so the markup isn't escaped.
    expect(File::get(app_path('Http/Controllers/Backend/GizmoController.php')))
        ->toContain("->editColumn('status'")
        ->toContain('class="ac-status" data-status="')
        ->toMatch('/rawColumns\(\[[^\]]*\bstatus\b/');

    // Detail screen: same pill via the reusable component (it reads the enum and emits the .ac-status pill).
    expect(File::get(resource_path('views/backend/pages/gizmos/show.blade.php')))
        ->toContain('<x-admin-core::status :value="$object->status" />');
});

it('keys edit/show route links by the public route key, not the bigint id (hybrid keys)', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string',
        '--migration' => true,
    ])->assertSuccessful();

    // The update action and the show→edit link must use getRouteKey() (the uuid
    // under the hybrid strategy). Posting to `…/{id}` would resolve the binding
    // by uuid = <int> and blow up with an invalid-uuid SQL error.
    $edit = File::get(resource_path('views/backend/pages/gizmos/edit.blade.php'));
    expect($edit)
        ->toContain("route('admin.gizmos.update', \$object->getRouteKey())")
        ->not->toContain('$object->id');

    $show = File::get(resource_path('views/backend/pages/gizmos/show.blade.php'));
    expect($show)
        ->toContain("route('admin.gizmos.edit', \$object->getRouteKey())")
        ->not->toContain('$object->id');
});

it('gives create/edit/show a consistent page-header with a parent crumb', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string',
        '--migration' => true,
    ])->assertSuccessful();

    foreach (['create', 'edit', 'show'] as $view) {
        expect(File::get(resource_path("views/backend/pages/gizmos/{$view}.blade.php")))
            ->toContain('<x-admin-core::page-header')
            ->toContain('parent="Gizmos"');
    }

    // The legacy AdminLTE breadcrumb section is gone from show.
    expect(File::get(resource_path('views/backend/pages/gizmos/show.blade.php')))
        ->not->toContain("@section('breadcrumb')");
});

it('casts boolean / date / datetime / decimal columns on the model', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, active:boolean, born:date, published_at:datetime, price:decimal',
        '--migration' => true,
    ])->assertSuccessful();

    expect(File::get(app_path('Models/Gizmo.php')))
        ->toContain('protected function casts(): array')
        ->toContain("'active' => 'boolean'")
        ->toContain("'born' => 'date'")
        ->toContain("'published_at' => 'datetime'")
        ->toContain("'price' => 'decimal:2'")
        ->not->toContain("'name' => '"); // strings are not cast
});

it('omits the casts() method when no column needs casting', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, slug:string',
        '--migration' => true,
    ])->assertSuccessful();

    expect(File::get(app_path('Models/Gizmo.php')))
        ->not->toContain('protected function casts(): array');
});

it('scaffolds the extended field types (time / url / slug / json / password)', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Article',
        '--fields' => 'name:string, slug:slug, website:url, start_at:time, meta:json, secret:password',
        '--migration' => true,
    ])->assertSuccessful();

    // Migration: time + json column types; slug is nullable + unique.
    $migration = collect(glob(database_path('migrations/*_create_articles_table.php')))->first();
    expect(File::get($migration))
        ->toContain("\$table->time('start_at')")
        ->toContain("\$table->json('meta')")
        ->toContain("\$table->string('slug')->nullable()->unique()");

    // Model: json → array cast, password → hashed cast + $hidden, slug derived from name.
    expect(File::get(app_path('Models/Article.php')))
        ->toContain("'meta' => 'array'")
        ->toContain("'secret' => 'hashed'")
        ->toContain("protected \$hidden = ['secret'];")   // password kept out of array/JSON output
        ->toContain('$model->slug ??= \\Illuminate\\Support\\Str::slug($model->name)');

    // Validation: url, time format, slug alpha_dash, password min length.
    $store = File::get(app_path('Http/Requests/Article/StoreArticleRequest.php'));
    expect($store)
        ->toContain("'website' => ['required', 'url', 'max:255']")
        ->toContain("'start_at' => ['required', 'date_format:H:i,H:i:s']")
        ->toContain("'alpha_dash'")
        ->toContain("'secret' => ['required', 'string', 'min:8']")
        ->toContain('json_decode($this->meta, true)'); // JSON textarea decoded before validation

    // Update: password optional, blank password dropped so the hash survives.
    $update = File::get(app_path('Http/Requests/Article/UpdateArticleRequest.php'));
    expect($update)
        ->toContain("'secret' => ['nullable', 'string', 'min:8']")
        ->toContain("\$this->request->remove('secret')");

    // Form controls.
    expect(File::get(resource_path('views/backend/pages/articles/partials/form.blade.php')))
        ->toContain('type="url"')
        ->toContain('type="time"')
        ->toContain('type="password"')
        ->toContain('json_encode(');

    // password is write-only: it has a form input (above) but is NOT shown in the index
    // table, the DataTable columns, or the detail view (no bcrypt hash on display).
    expect(File::get(resource_path('views/backend/pages/articles/partials/thead.blade.php')))->not->toContain('Secret');
    expect(File::get(resource_path('views/backend/pages/articles/partials/scripts.blade.php')))->not->toContain("data: 'secret'");
    expect(File::get(resource_path('views/backend/pages/articles/show.blade.php')))->not->toContain('$object->secret');

    // Clean up Article (not part of the standard gizmo target set).
    foreach ([
        app_path('Models/Article.php'),
        app_path('Http/Controllers/Backend/ArticleController.php'),
        app_path('Http/Requests/Article'),
        app_path('Services/Articles'),
        app_path('Policies/ArticlePolicy.php'),
        base_path('routes/Web/Backend/Modules/articles.php'),
        resource_path('views/backend/pages/articles'),
        database_path('factories/ArticleFactory.php'),
        database_path('seeders/ArticleSeeder.php'),
        $migration,
    ] as $path) {
        File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);
    }
});

it('generates a CRUD feature test with --tests', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, avatar:image',
        '--tests' => true,
    ])->assertSuccessful();

    $path = base_path('tests/Feature/GizmoTest.php');
    expect(File::exists($path))->toBeTrue();
    expect(File::get($path))
        ->toContain('class GizmoTest extends TestCase')
        ->toContain("route('admin.gizmos.update', \$object->getRouteKey())") // hybrid route key
        ->toContain('-gizmo"')                                               // resource permission (e.g. "{$ability}-gizmo")
        ->toContain('->assertForbidden()')                                   // permission gating
        ->toContain("\$payload['avatar'] = \\Illuminate\\Http\\UploadedFile::fake()->image('avatar.jpg')")
        ->toContain('assertModelMissing($object)');                          // hard delete
});

it('asserts soft deletion in the generated test for a soft-deletes resource', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string',
        '--soft-deletes' => true,
        '--tests' => true,
    ])->assertSuccessful();

    expect(File::get(base_path('tests/Feature/GizmoTest.php')))
        ->toContain('assertSoftDeleted($object)')
        ->not->toContain('assertModelMissing');
});

it('generates a JSON API with --api (resource + controller + routes)', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string, secret:password, category_id:foreign',
        '--api' => true,
    ])->assertSuccessful();

    // JsonResource: public uuid id, the password is never exposed, relation by name.
    expect(File::get(app_path('Http/Resources/GizmoResource.php')))
        ->toContain('class GizmoResource extends JsonResource')
        ->toContain("'id' => \$this->getRouteKey()")
        ->toContain("'category' => \$this->category?->name")
        ->not->toContain("'secret'");

    // The web controller appends the belongsTo's related name to the CSV export.
    expect(File::get(app_path('Http/Controllers/Backend/GizmoController.php')))
        ->toContain("\$this->exportRelations = ['category'];");

    // Thin API controller: the CRUD actions live on the base ApiController, the
    // generated class just wires the service, resource and form requests.
    expect(File::get(app_path('Http/Controllers/Api/GizmoApiController.php')))
        ->toContain('class GizmoApiController extends ApiController')
        ->toContain('$this->resource = GizmoResource::class')
        ->toContain('$this->storeRequest = StoreGizmoRequest::class')
        ->toContain('$this->updateRequest = UpdateGizmoRequest::class')
        // List-query whitelists derived from the fields: name searchable, category filterable.
        ->toContain("protected array \$searchable = ['name']")
        ->toContain("protected array \$sortable = ['name', 'created_at']")
        ->toContain("protected array \$filterable = ['category_id']")
        // Eager-load the relation on the list so the resource's $this->category?->name doesn't N+1.
        ->toContain("protected array \$with = ['category']");

    // Sanctum-gated apiResource routes under api.gizmos.*, each action gated by the same permission as
    // the web admin (delete → delete-gizmo, etc.) — via AuthorizeApiPermission, which resolves it on the
    // web permission guard (not the API auth guard) so a web admin's token is authorized.
    expect(File::get(base_path('routes/Api/Modules/gizmos.php')))
        ->toContain("config('admin-core.api.middleware'")
        ->toContain("->name('api.gizmos.')")
        ->toContain("[GizmoApiController::class, 'index']")
        ->toContain('use Ngos\AdminCore\Http\Middleware\AuthorizeApiPermission;')
        ->toContain("AuthorizeApiPermission::class . ':' . \$action . '-gizmo'")
        ->toContain("'destroy'])->name('destroy')->middleware(\$gate('delete'))")
        ->toContain("->middleware(\$gate('list'))")
        // The guard token is a middleware-arg fragment (',merchant'), never injected into prose. A plain
        // resource left it empty, so the comment used to read "pins that guard via ." — a broken sentence.
        ->not->toContain('__AC_PERM_GUARD__')
        ->not->toContain('via .');
});

it('wires routes/api.php to load the generated API modules with --api (idempotent)', function () {
    $api = base_path('routes/api.php');
    File::ensureDirectoryExists(dirname($api));
    File::put($api, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");

    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string', '--api' => true])
        ->assertSuccessful();

    // The module loader is appended, so /api/gizmos actually resolves (was only wired by --api-auth).
    expect(File::get($api))
        ->toContain('>>> admin-core:api-modules')
        ->toContain("glob(__DIR__ . '/Api/Modules/*.php')");

    // Re-running --api doesn't add the loader a second time.
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string', '--api' => true, '--force' => true])
        ->assertSuccessful();
    expect(substr_count(File::get($api), '>>> admin-core:api-modules'))->toBe(1);

    File::delete($api);
    File::deleteDirectory(base_path('routes/Api'));
});

it('warns (does not silently fail) when --api is used but routes/api.php is absent', function () {
    expect(File::exists(base_path('routes/api.php')))->toBeFalse(); // testbench has none

    // The guidance must be actionable — point at install:api AND loading the modules (the old "load
    // automatically" wording sent users to a dead end). Assert wrap-safe contiguous tokens.
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string', '--api' => true])
        ->expectsOutputToContain('install:api')
        ->expectsOutputToContain('routes/Api/Modules')
        ->assertSuccessful();

    expect(File::exists(base_path('routes/api.php')))->toBeFalse(); // not created behind your back

    File::deleteDirectory(base_path('routes/Api'));
});

it('generates only the API channel with --api-only (no web files, no sidebar link)', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string',
        '--api-only' => true,
        '--migration' => true,
    ])->assertSuccessful();

    // Shared core + API channel exist…
    expect(File::exists(app_path('Models/Gizmo.php')))->toBeTrue();
    expect(File::exists(app_path('Services/Gizmos/GizmoService.php')))->toBeTrue();
    expect(File::exists(app_path('Http/Controllers/Api/GizmoApiController.php')))->toBeTrue();
    expect(File::exists(base_path('routes/Api/Modules/gizmos.php')))->toBeTrue();

    // …but nothing from the web channel.
    expect(File::exists(app_path('Http/Controllers/Backend/GizmoController.php')))->toBeFalse();
    expect(File::exists(base_path('routes/Web/Backend/Modules/gizmos.php')))->toBeFalse();
    expect(File::isDirectory(resource_path('views/backend/pages/gizmos')))->toBeFalse();
});

it('adds the API channel to an existing web resource (and web to an api-only one)', function () {
    // 1) web-only first…
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string'])->assertSuccessful();
    expect(File::exists(app_path('Http/Controllers/Api/GizmoApiController.php')))->toBeFalse();
    $webController = File::get(app_path('Http/Controllers/Backend/GizmoController.php'));

    // …then re-run with --api: API files appear, existing web files untouched.
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string', '--api' => true])
        ->assertSuccessful();
    expect(File::exists(app_path('Http/Controllers/Api/GizmoApiController.php')))->toBeTrue();
    expect(File::exists(base_path('routes/Api/Modules/gizmos.php')))->toBeTrue();
    expect(File::get(app_path('Http/Controllers/Backend/GizmoController.php')))->toBe($webController);

    cleanupGizmo();

    // 2) the reverse: api-only first, then add the web channel by re-running plain.
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string', '--api-only' => true])
        ->assertSuccessful();
    $apiController = File::get(app_path('Http/Controllers/Api/GizmoApiController.php'));

    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string'])->assertSuccessful();
    expect(File::exists(app_path('Http/Controllers/Backend/GizmoController.php')))->toBeTrue();
    expect(File::exists(resource_path('views/backend/pages/gizmos/index.blade.php')))->toBeTrue();
    expect(File::get(app_path('Http/Controllers/Api/GizmoApiController.php')))->toBe($apiController);
});

it('infers fields from the existing model when adding a channel without --fields', function () {
    // A web-only resource with types the model does NOT all cast (integer/time have no cast).
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--migration' => true,
        '--fields' => 'title:string, status:enum:draft|live, qty:integer, open_at:time, category_id:foreign',
    ])->assertSuccessful();

    // Add the API channel with NO --fields — fields are reconstructed from the model + migration.
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--api' => true])
        ->expectsOutputToContain('title:string, status:enum:draft|live, qty:integer, open_at:time, category_id:foreign')
        ->assertSuccessful();

    // Resource carries every field (enum + foreign resolved) — no re-typing needed.
    expect(File::get(app_path('Http/Resources/GizmoResource.php')))
        ->toContain("'title' => \$this->title")
        ->toContain("'status' => \$this->status")
        ->toContain("'qty' => \$this->qty")
        ->toContain("'category' => \$this->category?->name");

    // Whitelists are type-correct: the integer/time columns stay OUT of $searchable
    // (a LIKE on them errors on Postgres); enum + foreign go to $filterable.
    expect(File::get(app_path('Http/Controllers/Api/GizmoApiController.php')))
        ->toContain("\$searchable = ['title']")                         // not qty / open_at / status
        ->toContain("\$sortable = ['title', 'status', 'qty', 'open_at', 'created_at']")
        ->toContain("\$filterable = ['status', 'category_id']");
});

it('points filter-tabs at the enum column counting only displayed columns (password is skipped)', function () {
    // Order: title(col 1), secret(write-only, NO column), status(enum). The enum's real
    // DataTable column is 2 (checkbox 0, title 1, status 2) — not 3 as a raw field index.
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'title:string, secret:password, status:enum:new|paid',
        '--migration' => true,
    ])->assertSuccessful();

    $index = File::get(resource_path('views/backend/pages/gizmos/index.blade.php'));
    expect($index)->toContain('filter-tabs')->toContain(':column="2"');

    expect(File::get(resource_path('views/backend/pages/gizmos/partials/scripts.blade.php')))
        ->not->toContain("data: 'secret'");           // password is not a table column
});

it('routes a resource into a portal with --portal (dir + route-names + controller prefix + guard)', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string',
        '--portal' => 'merchant',
    ])->assertSuccessful();

    // The route module lands in the portal's Modules dir, gated by the merchant guard.
    expect(File::exists(base_path('routes/Merchant/Modules/gizmos.php')))->toBeTrue()
        ->and(File::get(base_path('routes/Merchant/Modules/gizmos.php')))
            ->toContain("Route::crud('gizmo', GizmoController::class, 'merchant')")
            ->toContain('permission:list-gizmo,merchant');

    // Controller redirects resolve inside the merchant route group, not admin.
    expect(File::get(app_path('Http/Controllers/Backend/GizmoController.php')))
        ->toContain("\$this->routePrefix = 'merchant.';");

    // No foreign field here → no exportRelations line is emitted.
    expect(File::get(app_path('Http/Controllers/Backend/GizmoController.php')))
        ->not->toContain('exportRelations');

    // Views use merchant.* route-names (no admin.* leakage)…
    expect(File::get(resource_path('views/backend/pages/gizmos/index.blade.php')))
        ->toContain("route('merchant.gizmos.create')")
        ->not->toContain("route('admin.gizmos");

    // …and render inside the merchant portal layout, not the admin one.
    foreach (['index', 'create', 'edit', 'show'] as $view) {
        expect(File::get(resource_path("views/backend/pages/gizmos/{$view}.blade.php")))
            ->toContain("@extends('merchant.layout')")
            ->not->toContain("@extends('backend.layouts.app')");
    }

    File::deleteDirectory(base_path('routes/Merchant'));
});

it('skips --tests for a portal/guard resource (the scaffold assumes the default guard) and warns', function () {
    // The default-guard case (a test file IS generated) is covered above; here a guard-scoped
    // resource must NOT emit the web-guard test that would 403, and should explain why.
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string',
        '--portal' => 'merchant',
        '--tests' => true,
    ])->expectsOutputToContain('Skipped --tests')->assertSuccessful();

    expect(File::exists(base_path('tests/Feature/GizmoTest.php')))->toBeFalse();

    File::deleteDirectory(base_path('routes/Merchant'));
});

it('scopes the route gates to a guard with --guard (multi-portal), default stays clean', function () {
    // A merchant-portal resource: the crud macro + every permission gate carry the guard.
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string',
        '--guard' => 'merchant',
        '--soft-deletes' => true,
    ])->assertSuccessful();

    expect(File::get(base_path('routes/Web/Backend/Modules/gizmos.php')))
        ->toContain("Route::crud('gizmo', GizmoController::class, 'merchant')")
        ->toContain("'permission:list-gizmo,merchant'")
        ->toContain("'permission:create-gizmo,merchant'")
        ->toContain("'permission:delete-gizmo,merchant'");   // incl. the soft-delete group

    cleanupGizmo();

    // No --guard → no guard arg, no suffix (unchanged behaviour).
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string'])->assertSuccessful();
    expect(File::get(base_path('routes/Web/Backend/Modules/gizmos.php')))
        ->toContain("Route::crud('gizmo', GizmoController::class)")
        ->toContain("'permission:list-gizmo'")
        ->not->toContain(',merchant');
});

it('omits filter tabs when there is no enum field', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string',
        '--migration' => true,
    ])->assertSuccessful();

    expect(File::get(resource_path('views/backend/pages/gizmos/index.blade.php')))
        ->not->toContain('filter-tabs')
        ->not->toContain('__AC_FILTER_TABS__');
});

it('adds a sort toggle and reorder route with --sortable', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string',
        '--sortable' => true,
        '--migration' => true,
    ])->assertSuccessful();

    expect(File::get(resource_path('views/backend/pages/gizmos/index.blade.php')))
        ->toContain('toggle-sort')->toContain('sort-panel')
        ->and(File::get(base_path('routes/Web/Backend/Modules/gizmos.php')))->toContain('reorder')
        ->and(File::get(glob(database_path('migrations/*_create_gizmos_table.php'))[0]))->toContain("'sort'");
});

it('adds a trash screen and soft-delete routes with --soft-deletes', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'name:string',
        '--soft-deletes' => true,
        '--migration' => true,
    ])->assertSuccessful();

    expect(File::exists(resource_path('views/backend/pages/gizmos/trash.blade.php')))->toBeTrue()
        ->and(File::get(base_path('routes/Web/Backend/Modules/gizmos.php')))
            ->toContain('restore')->toContain('forceDelete')
        ->and(File::get(app_path('Models/Gizmo.php')))->toContain('SoftDeletes')
        ->and(File::get(glob(database_path('migrations/*_create_gizmos_table.php'))[0]))->toContain('softDeletes');
});

it('adds a plain index with the # modifier (but not when the column is already unique)', function () {
    $this->artisan('admin-core:make', [
        'name' => 'Gizmo',
        '--fields' => 'status:enum:new|paid#, placed_at:datetime#, ref:string^#, name:string',
        '--migration' => true,
    ])->assertSuccessful();

    $migration = File::get(glob(database_path('migrations/*_create_gizmos_table.php'))[0]);
    expect($migration)
        ->toContain("\$table->string('status')->index();")
        ->toContain("\$table->dateTime('placed_at')->index();")
        ->toContain("\$table->string('ref')->unique();")   // ^# → unique only (no double index)
        ->not->toContain("\$table->string('ref')->unique()->index();")
        ->and(substr_count($migration, '->index()'))->toBe(2)   // status + placed_at, not ref/name
        ->and($migration)->toContain("\$table->string('name');"); // plain, no index
});

it('never duplicates the migration on a second run', function () {
    $args = ['name' => 'Gizmo', '--fields' => 'name:string', '--migration' => true];

    $this->artisan('admin-core:make', $args)->assertSuccessful();
    $this->artisan('admin-core:make', $args)->assertSuccessful();

    // The second run must reuse (not duplicate) the create migration.
    expect(glob(database_path('migrations/*_create_gizmos_table.php')))->toHaveCount(1);
});

it('skips existing files unless --force is given', function () {
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string'])->assertSuccessful();

    $model = app_path('Models/Gizmo.php');
    File::put($model, '<?php // hand-edited sentinel');

    // No --force: the hand edit survives.
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string'])->assertSuccessful();
    expect(File::get($model))->toContain('hand-edited sentinel');

    // --force: regenerated.
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--fields' => 'name:string', '--force' => true])->assertSuccessful();
    expect(File::get($model))->not->toContain('hand-edited sentinel')->toContain('class Gizmo');
});

it('builds the field list interactively when --fields is omitted', function () {
    // No --fields on a brand-new resource → prompt-for-missing-input: name, type, modifiers, repeat.
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--migration' => true])
        // 1st field → title:string  (not nullable, not unique)
        ->expectsQuestion('Field name', 'title')
        ->expectsQuestion('Type for "title"', 'string')
        ->expectsConfirmation('Nullable (optional)?', 'no')
        ->expectsConfirmation('Unique?', 'no')
        // 2nd field → price:decimal?  (nullable; decimal isn't unique-eligible, so no Unique prompt)
        ->expectsQuestion('Field name (blank to finish)', 'price')
        ->expectsQuestion('Type for "price"', 'decimal')
        ->expectsConfirmation('Nullable (optional)?', 'yes')
        // blank name ends the loop
        ->expectsQuestion('Field name (blank to finish)', '')
        ->assertSuccessful();

    $migration = collect(glob(database_path('migrations/*_create_gizmos_table.php')))->first();
    expect(File::get($migration))
        ->toContain("\$table->string('title')")
        ->toContain("\$table->decimal('price', 10, 2)->nullable()");
    // The assembled DSL drove the model too.
    expect(File::get(app_path('Models/Gizmo.php')))->toContain("'title', 'price'");
});

it('normalises a foreign field name to the *_id convention in the interactive builder', function () {
    $this->artisan('admin-core:make', ['name' => 'Gizmo', '--migration' => true])
        // "category" + foreign → renamed to category_id (belongsTo); not nullable
        ->expectsQuestion('Field name', 'category')
        ->expectsQuestion('Type for "category"', 'foreign')
        ->expectsConfirmation('Nullable (optional)?', 'no')
        ->expectsQuestion('Field name (blank to finish)', '')
        ->assertSuccessful();

    $migration = collect(glob(database_path('migrations/*_create_gizmos_table.php')))->first();
    expect(File::get($migration))->toContain("\$table->foreignId('category_id')");
});

it('prints the full field-type catalog with --list-fields and generates nothing', function () {
    $this->artisan('admin-core:make', ['--list-fields' => true])
        ->expectsOutputToContain('belongsToMany')
        ->expectsOutputToContain('enum:draft|published')
        // The catalog must be complete — these real DSL types were missing before.
        ->expectsOutputToContain('time of day')
        ->expectsOutputToContain('web address')
        ->expectsOutputToContain('structured data')
        ->expectsOutputToContain('write-once')
        ->assertSuccessful();

    expect(File::exists(app_path('Models/Gizmo.php')))->toBeFalse();
});

it('fails with a helpful message when no resource name is given', function () {
    $this->artisan('admin-core:make')
        ->expectsOutputToContain('Missing the resource name')
        ->assertFailed();
});
