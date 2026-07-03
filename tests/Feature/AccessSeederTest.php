<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The --access AccessSeeder grants the admin role every permission — but ONLY on its own guard. A portal
 * resource (admin-core:make --guard=…/--portal=…) seeds permissions on other guards, and Spatie's
 * syncPermissions() throws GuardDoesNotMatch if handed a permission whose guard differs from the role's.
 */

beforeEach(function () {
    foreach (['permissions', 'roles', 'role_has_permissions', 'model_has_roles', 'model_has_permissions'] as $t) {
        Schema::dropIfExists($t);
    }
    Schema::create('permissions', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name');
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
});

afterEach(function () {
    foreach (['permissions', 'roles', 'role_has_permissions', 'model_has_roles', 'model_has_permissions'] as $t) {
        Schema::dropIfExists($t);
    }
});

it('grants the admin role only its own guard\'s permissions (no GuardDoesNotMatch when a portal guard exists)', function () {
    Permission::firstOrCreate(['name' => 'list-widget', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'list-order', 'guard_name' => 'merchant']); // a --guard=merchant resource

    $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    // The exact (fixed) AccessSeeder step — must not throw, and must grant only the web-guard permission.
    $admin->syncPermissions(Permission::where('guard_name', $admin->guard_name)->get());

    expect($admin->permissions->pluck('name')->all())->toBe(['list-widget']); // merchant permission excluded
});

it('ships the guard-scoped sync in the AccessSeeder stub (not Permission::all())', function () {
    $stub = (string) file_get_contents(dirname(__DIR__, 2) . '/stubs/access/database/seeders/AccessSeeder.php.stub');
    expect($stub)
        ->toContain("Permission::where('guard_name', \$admin->guard_name)->get()")
        ->not->toContain('syncPermissions(Permission::all())');
});

it('crashes with GuardDoesNotMatch when handed every guard\'s permission (the pre-fix behaviour)', function () {
    Permission::firstOrCreate(['name' => 'list-widget', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'list-order', 'guard_name' => 'merchant']);
    $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    // Documents WHY the fix is needed: the old `Permission::all()` throws once a non-default-guard permission exists.
    expect(fn () => $admin->syncPermissions(Permission::all()))
        ->toThrow(\Spatie\Permission\Exceptions\GuardDoesNotMatch::class);
});
