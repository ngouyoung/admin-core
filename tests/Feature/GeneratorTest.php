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
