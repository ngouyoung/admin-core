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
    ];
}

function cleanupGizmo(): void
{
    foreach (gizmoTargets() as $path) {
        File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);
    }
    foreach (glob(database_path('migrations/*_create_gizmos_table.php')) ?: [] as $migration) {
        File::delete($migration);
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
    expect(File::get(resource_path('views/backend/pages/gizmos/index.blade.php')))
        ->toContain('<x-admin-core::filter-tabs')
        ->toContain('table="#gizmos_table"')
        ->toContain(':column="2"')
        ->toContain("'draft' => 'Draft'")
        ->toContain("'published' => 'Published'");
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

    // Detail screen: same pill.
    expect(File::get(resource_path('views/backend/pages/gizmos/show.blade.php')))
        ->toContain('<span class="ac-status" data-status="{{ $object->status }}">');
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
