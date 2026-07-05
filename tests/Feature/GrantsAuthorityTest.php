<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * GrantsWithinOwnAuthority — the access kit's least-privilege guard: an actor may only hand out
 * authority they already hold. Without it, `edit-role` alone syncs EVERY permission onto the actor's
 * own role, and `edit-user` alone assigns the super role to the actor — self-service escalation.
 */

/** A minimal authenticatable with spatie roles, standing in for the host app's User. */
class GrantTestUser extends \Illuminate\Foundation\Auth\User
{
    use \Spatie\Permission\Traits\HasRoles;

    protected $table = 'grant_users';

    protected $fillable = ['name'];

    protected $guard_name = 'web';
}

/** Exposes the trait's protected asserts for direct exercise. */
class GrantHarness
{
    use \Ngos\AdminCore\Services\Concerns\GrantsWithinOwnAuthority;

    public function grantPermissions($permissions, $currentIds = []): void
    {
        $this->assertCanGrantPermissions(collect($permissions), $currentIds);
    }

    public function grantRoles($roles, $currentIds = []): void
    {
        $this->assertCanGrantRoles(collect($roles), $currentIds);
    }
}

beforeEach(function () {
    foreach (['permissions', 'roles', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'grant_users'] as $t) {
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
    Schema::create('grant_users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    config()->set('admin-core.permission.super_role', 'admin');
});

afterEach(function () {
    foreach (['permissions', 'roles', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'grant_users'] as $t) {
        Schema::dropIfExists($t);
    }
});

it('refuses granting a permission the actor does not hold (the edit-role escalation)', function () {
    $editRole = Permission::create(['name' => 'edit-role', 'guard_name' => 'web']);
    $deleteUser = Permission::create(['name' => 'delete-user', 'guard_name' => 'web']);
    $support = Role::create(['name' => 'support', 'guard_name' => 'web']);
    $support->syncPermissions([$editRole]);

    $actor = GrantTestUser::create(['name' => 'Support']);
    $actor->assignRole($support);
    $this->actingAs($actor);

    // The actor holds only edit-role — handing out delete-user is exactly the escalation.
    expect(fn () => (new GrantHarness)->grantPermissions([$editRole, $deleteUser]))
        ->toThrow(AuthorizationException::class, 'delete-user');

    // Granting within their own authority still works.
    (new GrantHarness)->grantPermissions([$editRole]);
    expect(true)->toBeTrue(); // no exception
});

it('allows resubmitting a role\'s CURRENT grants (renaming must not require holding them)', function () {
    $deleteUser = Permission::create(['name' => 'delete-user', 'guard_name' => 'web']);
    $editRole = Permission::create(['name' => 'edit-role', 'guard_name' => 'web']);
    $support = Role::create(['name' => 'support', 'guard_name' => 'web']);
    $support->syncPermissions([$editRole]);

    $actor = GrantTestUser::create(['name' => 'Support']);
    $actor->assignRole($support);
    $this->actingAs($actor);

    // The edited role ALREADY has delete-user; the form resubmits it — not a new grant, allowed.
    (new GrantHarness)->grantPermissions([$deleteUser], [$deleteUser->id]);
    expect(true)->toBeTrue();

    // But the same permission as a NEW addition (not in currentIds) is refused.
    expect(fn () => (new GrantHarness)->grantPermissions([$deleteUser], []))
        ->toThrow(AuthorizationException::class);
});

it('refuses assigning the super role unless the actor holds it (the edit-user escalation)', function () {
    $admin = Role::create(['name' => 'admin', 'guard_name' => 'web']); // super, NO explicit permissions
    $editUser = Permission::create(['name' => 'edit-user', 'guard_name' => 'web']);
    $support = Role::create(['name' => 'support', 'guard_name' => 'web']);
    $support->syncPermissions([$editUser]);

    $actor = GrantTestUser::create(['name' => 'Support']);
    $actor->assignRole($support);
    $this->actingAs($actor);

    // The super role carries no explicit permissions (Gate::before bypass) — the subset check can't
    // protect it, so only a super holder may assign it.
    expect(fn () => (new GrantHarness)->grantRoles([$admin]))
        ->toThrow(AuthorizationException::class, 'admin');

    // A role whose permissions the actor holds is assignable…
    (new GrantHarness)->grantRoles([$support]);
    expect(true)->toBeTrue();

    // …but a role carrying a permission beyond the actor's authority is not.
    $deleteUser = Permission::create(['name' => 'delete-user', 'guard_name' => 'web']);
    $ops = Role::create(['name' => 'ops', 'guard_name' => 'web']);
    $ops->syncPermissions([$deleteUser]);
    expect(fn () => (new GrantHarness)->grantRoles([$ops]))
        ->toThrow(AuthorizationException::class, 'delete-user');
});

it('exempts the super role holder and the console (no actor) context', function () {
    $deleteUser = Permission::create(['name' => 'delete-user', 'guard_name' => 'web']);
    $admin = Role::create(['name' => 'admin', 'guard_name' => 'web']);

    // Console/seeder: no authenticated user — provisioning must not be blocked.
    (new GrantHarness)->grantPermissions([$deleteUser]);
    (new GrantHarness)->grantRoles([$admin]);

    // A super-role holder grants anything, including the super role itself.
    $boss = GrantTestUser::create(['name' => 'Boss']);
    $boss->assignRole($admin);
    $this->actingAs($boss);
    (new GrantHarness)->grantPermissions([$deleteUser]);
    (new GrantHarness)->grantRoles([$admin]);
    expect(true)->toBeTrue(); // none threw
});

it('ships the guard wired into both access stubs', function () {
    $role = file_get_contents(__DIR__ . '/../../stubs/access/Services/Roles/RoleService.php.stub');
    $user = file_get_contents(__DIR__ . '/../../stubs/access/Services/Users/UserService.php.stub');

    expect($role)->toContain('use GrantsWithinOwnAuthority;')
        ->toContain('$this->assertCanGrantPermissions($granted, []);')                       // create: everything is new
        ->toContain('$this->assertCanGrantPermissions($granted, $role->permissions->pluck(\'id\'));'); // update: added only
    expect($user)->toContain('use GrantsWithinOwnAuthority;')
        ->toContain('$this->assertCanGrantRoles($assigned, []);')
        ->toContain('$this->assertCanGrantRoles($assigned, $user->roles->pluck(\'id\'));');
});
