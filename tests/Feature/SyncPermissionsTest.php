<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Support\Permissions\RouteResourceDiscovery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * admin-core:sync-permissions — the first Synchronization Engine. make is now filesystem-only; this command
 * creates + grants the CRUD permissions the ROUTES enforce, idempotently, and degrades to a report when the
 * database is unavailable. Discovery is by route middleware (permission:{action}-{resource}[,{guard}]).
 */

function syncPermTables(): void
{
    foreach (['permissions', 'roles', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'group_permissions'] as $t) {
        Schema::dropIfExists($t);
    }
    Schema::create('permissions', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name');
        $t->unsignedBigInteger('group_id')->nullable();
        $t->timestamps();
    });
    Schema::create('roles', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name');
        $t->timestamps();
    });
    Schema::create('role_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->unsignedBigInteger('role_id');
        $t->primary(['permission_id', 'role_id']);
    });
    Schema::create('model_has_roles', function (Blueprint $t) {
        $t->unsignedBigInteger('role_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
    });
    Schema::create('model_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
    });
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
}

/** Register CRUD routes for a resource, gated by permission middleware (as Route::crud does when enabled). */
function registerCrudRoutes(string $resource, ?string $guard = null): void
{
    $suffix = $guard ? ",{$guard}" : '';
    foreach (['list', 'create', 'edit', 'delete'] as $action) {
        Route::middleware("permission:{$action}-{$resource}{$suffix}")
            ->get("admin/{$resource}-x/{$action}", fn () => 'ok')
            ->name("admin.{$resource}.{$action}");
    }
}

beforeEach(function () {
    config()->set('admin-core.permission.enabled', true);
    config()->set('admin-core.permission.super_role', 'admin');
    config()->set('admin-core.permission.default_roles', []);
    syncPermTables();
});

afterEach(function () {
    foreach (['permissions', 'roles', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'group_permissions'] as $t) {
        Schema::dropIfExists($t);
    }
});

// ── discovery + route parsing ────────────────────────────────────────────────────────────────────────

it('discovers CRUD permissions from route middleware, grouped per resource', function () {
    registerCrudRoutes('region');

    $specs = app(RouteResourceDiscovery::class)->discover();

    expect($specs)->toHaveCount(1);
    $spec = array_values($specs)[0];
    expect($spec->resource)->toBe('region')
        ->and($spec->guard)->toBe('web')
        ->and($spec->actions)->toBe(['list', 'create', 'edit', 'delete'])
        ->and($spec->permissionNames())->toBe(['list-region', 'create-region', 'edit-region', 'delete-region'])
        ->and($spec->label())->toBe('Regions')
        ->and($spec->group())->toBe('Regions Management');
});

it('parses a multi-hyphen resource and ignores non-CRUD permission middleware', function () {
    registerCrudRoutes('course-review');
    Route::middleware('permission:manage-media')->get('admin/media', fn () => 'ok')->name('admin.media.index');
    Route::middleware('permission:publish-course')->post('admin/courses/publish', fn () => 'ok')->name('admin.courses.publish');

    $specs = app(RouteResourceDiscovery::class)->discover();

    expect(array_keys($specs))->toBe(["web\0course-review"]) // media (manage) + publish (custom) excluded
        ->and(array_values($specs)[0]->permissionNames())->toContain('list-course-review');
});

it('handles a portal guard from the middleware suffix', function () {
    registerCrudRoutes('order', 'merchant');

    $specs = app(RouteResourceDiscovery::class)->discover();
    $spec = array_values($specs)[0];

    expect($spec->guard)->toBe('merchant')
        ->and($spec->key())->toBe("merchant\0order");
});

// ── create + grant + report ──────────────────────────────────────────────────────────────────────────

it('creates the missing permissions and grants the super role', function () {
    registerCrudRoutes('region');
    Role::create(['name' => 'admin', 'guard_name' => 'web']);

    $this->artisan('admin-core:sync-permissions')->assertSuccessful();

    expect(Permission::pluck('name')->sort()->values()->all())
        ->toBe(['create-region', 'delete-region', 'edit-region', 'list-region']);

    $admin = Role::where('name', 'admin')->first();
    expect($admin->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['create-region', 'delete-region', 'edit-region', 'list-region']);
});

it('produces a grouped summary report', function () {
    registerCrudRoutes('region');
    Role::create(['name' => 'admin', 'guard_name' => 'web']);

    $this->artisan('admin-core:sync-permissions')
        ->expectsOutputToContain('Regions')
        ->expectsOutputToContain('created:')
        ->expectsOutputToContain('list-region')
        ->expectsOutputToContain('granted:')
        ->assertSuccessful();
});

it('grants the roles named by --role instead of the default super role', function () {
    registerCrudRoutes('region');
    Role::create(['name' => 'admin', 'guard_name' => 'web']);
    Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $this->artisan('admin-core:sync-permissions', ['--role' => ['editor']])->assertSuccessful();

    expect(Role::where('name', 'editor')->first()->permissions)->toHaveCount(4)
        ->and(Role::where('name', 'admin')->first()->permissions)->toHaveCount(0);
});

// ── idempotency ──────────────────────────────────────────────────────────────────────────────────────

it('is idempotent — repeated runs never duplicate rows or grants', function () {
    registerCrudRoutes('region');
    Role::create(['name' => 'admin', 'guard_name' => 'web']);

    $this->artisan('admin-core:sync-permissions')->assertSuccessful();
    $this->artisan('admin-core:sync-permissions')->assertSuccessful();
    $this->artisan('admin-core:sync-permissions')->assertSuccessful();

    expect(Permission::count())->toBe(4)
        ->and(Role::where('name', 'admin')->first()->permissions()->count())->toBe(4);

    // The 2nd run reports the rows as already present, not created again.
    $this->artisan('admin-core:sync-permissions')
        ->expectsOutputToContain('already present')
        ->assertSuccessful();
});

// ── dry-run ──────────────────────────────────────────────────────────────────────────────────────────

it('writes nothing in --dry-run and reports the plan', function () {
    registerCrudRoutes('region');
    Role::create(['name' => 'admin', 'guard_name' => 'web']);

    $this->artisan('admin-core:sync-permissions', ['--dry-run' => true])
        ->expectsOutputToContain('would create')
        ->expectsOutputToContain('dry run')
        ->assertSuccessful();

    expect(Permission::count())->toBe(0);
});

// ── database unavailable ─────────────────────────────────────────────────────────────────────────────

it('a real run against an unavailable database reports + exits NON-ZERO (2) so CI detects it', function () {
    registerCrudRoutes('region');
    Schema::dropIfExists('permissions'); // permissions table gone → available() is false

    $this->artisan('admin-core:sync-permissions')
        ->expectsOutputToContain('Database unavailable.')
        ->expectsOutputToContain('Would create:')
        ->expectsOutputToContain('list-region')
        ->assertExitCode(2); // never throws, but signals failure to CI/CD
});

it('a --dry-run against an unavailable database reports + exits 0 (plan only)', function () {
    registerCrudRoutes('region');
    Schema::dropIfExists('permissions');

    $this->artisan('admin-core:sync-permissions', ['--dry-run' => true])
        ->expectsOutputToContain('Database unavailable.')
        ->expectsOutputToContain('list-region')
        ->assertSuccessful(); // exit 0 — a dry-run only reports the plan
});

// ── filters ──────────────────────────────────────────────────────────────────────────────────────────

it('only syncs the requested resource / guard', function () {
    registerCrudRoutes('region');
    registerCrudRoutes('station');
    Role::create(['name' => 'admin', 'guard_name' => 'web']);

    $this->artisan('admin-core:sync-permissions', ['--resource' => ['region']])->assertSuccessful();

    expect(Permission::pluck('name')->all())->toContain('list-region')
        ->and(Permission::where('name', 'like', '%-station')->count())->toBe(0);
});

// ── group filing (hybrid keys) — migrated from GeneratorTest ─────────────────────────────────────────

it('files permissions under a group permission that receives a uuid (hybrid keys)', function () {
    // The group-filing logic (relocated from the generator into PermissionSynchronizer) inserts the group
    // via the query builder, bypassing the model's HasPublicUuid hook — it must fill the uuid itself.
    config()->set('admin-core.permission.model', \Ngos\AdminCore\Tests\Fixtures\HybridPermission::class);
    registerCrudRoutes('gizmo');
    Schema::create('group_permissions', function (Blueprint $t) {
        $t->id();
        $t->uuid('uuid')->unique();
        $t->string('name');
        $t->unsignedBigInteger('parent_id')->nullable();
        $t->integer('sort')->default(0);
        $t->timestamps();
    });
    \Illuminate\Support\Facades\DB::table('group_permissions')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'All', 'sort' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('admin-core:sync-permissions')->assertSuccessful();

    $group = \Illuminate\Support\Facades\DB::table('group_permissions')->where('name', 'Gizmos Management')->first();
    expect($group)->not->toBeNull()
        ->and($group->uuid)->not->toBeNull()
        ->and(\Illuminate\Support\Facades\DB::table('permissions')->where('name', 'list-gizmo')->value('group_id'))->toEqual($group->id);
});

// ── doctor: Permission Health ────────────────────────────────────────────────────────────────────────

it('doctor flags route-enforced permissions with no DB row and recommends sync-permissions', function () {
    registerCrudRoutes('region'); // routes enforce list-region… but no permission rows exist yet

    $this->artisan('admin-core:doctor')
        ->expectsOutputToContain('Permission Health')
        ->expectsOutputToContain('list-region')
        ->expectsOutputToContain('Missing permissions detected.')
        ->expectsOutputToContain('Recommended action:')
        ->expectsOutputToContain('php artisan admin-core:sync-permissions')
        ->assertFailed();
});

it('doctor reports healthy once permissions are synced', function () {
    registerCrudRoutes('region');
    Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $this->artisan('admin-core:sync-permissions')->assertSuccessful();

    $this->artisan('admin-core:doctor')
        ->expectsOutputToContain('every route-enforced CRUD permission exists and is granted')
        ->assertSuccessful();
});

it('doctor does not stay red when the super role does not exist on the resource guard', function () {
    // A portal guard with no configured super_role: sync creates the perms but grants nothing (no such role
    // on that guard). Doctor must NOT then flag them "ungranted" forever with a remedy that can't clear it.
    registerCrudRoutes('order', 'merchant');
    $this->artisan('admin-core:sync-permissions')->assertSuccessful();
    expect(Permission::where('guard_name', 'merchant')->count())->toBe(4);

    $this->artisan('admin-core:doctor')
        ->expectsOutputToContain('every route-enforced CRUD permission exists and is granted')
        ->assertSuccessful();
});

it('never moves a permission an admin re-filed into a custom group (idempotent group filing)', function () {
    registerCrudRoutes('region');
    Schema::create('group_permissions', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('parent_id')->nullable();
        $t->integer('sort')->default(0);
        $t->timestamps();
    });
    \Illuminate\Support\Facades\DB::table('group_permissions')->insert(['name' => 'All', 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $custom = \Illuminate\Support\Facades\DB::table('group_permissions')->insertGetId(['name' => 'Reporting', 'sort' => 9, 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('admin-core:sync-permissions')->assertSuccessful(); // files under "Regions Management"
    \Illuminate\Support\Facades\DB::table('permissions')->where('name', 'list-region')->update(['group_id' => $custom]);

    $this->artisan('admin-core:sync-permissions')->assertSuccessful(); // must NOT move it back

    expect(\Illuminate\Support\Facades\DB::table('permissions')->where('name', 'list-region')->value('group_id'))->toEqual($custom);
});

it('is a no-op when permissions are disabled', function () {
    config()->set('admin-core.permission.enabled', false);
    registerCrudRoutes('region');

    $this->artisan('admin-core:sync-permissions')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();

    expect(Permission::count())->toBe(0);
});
