<?php

namespace Ngos\AdminCore\Support\Permissions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ngos\AdminCore\Support\Sync\SyncReport;
use Ngos\AdminCore\Support\Sync\Synchronizer;
use Spatie\Permission\PermissionRegistrar;

/**
 * The first Synchronization Engine: reconciles the CRUD permissions the routes enforce into the database.
 *
 * This holds the runtime-database logic that used to live inside `admin-core:make` (firstOrCreate the
 * `{action}-{resource}` permissions, file them under a `{Plural} Management` group, grant them to the
 * configured role(s), forget the permission cache) — now decoupled so generation is filesystem-only and
 * synchronization is an explicit, idempotent, database-availability-aware step. Pure runtime work: it never
 * touches the filesystem.
 *
 * Idempotent by construction — every write is `firstOrCreate` / Spatie `givePermissionTo` (both no-ops on
 * existing state), so repeated runs converge to identical state with no duplicates. Safe when the database
 * is unavailable: {@see synchronize()} degrades to a report-only plan and never throws.
 */
final class PermissionSynchronizer implements Synchronizer
{
    /** When set (via the --role option), grant to these roles instead of the configured defaults. @var list<string>|null */
    private ?array $rolesOverride = null;

    public function __construct(private readonly RouteResourceDiscovery $discovery) {}

    public function key(): string
    {
        return 'permissions';
    }

    /** Grant to an explicit role set for this run (CLI --role), overriding permission.default_roles. */
    public function useRoles(array $roles): self
    {
        $roles = array_values(array_filter(array_map('strval', $roles), fn ($r) => $r !== ''));
        $this->rolesOverride = $roles === [] ? null : $roles;

        return $this;
    }

    public function available(): bool
    {
        // Answers only "is the permissions store (database) reachable?" — NOT whether the feature is enabled
        // (callers check permission.enabled separately), so a future generic sync-runner can't mistake a
        // disabled feature for an unreachable store.
        try {
            return Schema::hasTable($this->permissionsTable());
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, ResourcePermissionSpec> */
    public function discover(): array
    {
        return $this->discovery->discover();
    }

    /**
     * @param  array<string, ResourcePermissionSpec>  $specs
     */
    public function synchronize(array $specs, bool $apply): SyncReport
    {
        $report = new SyncReport;
        $available = $this->available();
        $report->applied = $apply && $available;
        $canWrite = $apply && $available;

        /** @var class-string $permModel */
        $permModel = config('admin-core.permission.model', \Spatie\Permission\Models\Permission::class);

        foreach ($specs as $spec) {
            $section = $spec->label();
            $report->ensureSection($section);
            $names = $spec->permissionNames();

            // Which of these already exist is only knowable when the DB is reachable.
            $existing = $available
                ? $permModel::query()->whereIn('name', $names)->where('guard_name', $spec->guard)->pluck('name')->all()
                : [];

            foreach ($names as $name) {
                $isNew = ! in_array($name, $existing, true);
                if ($canWrite && $isNew) {
                    $permModel::firstOrCreate(['name' => $name, 'guard_name' => $spec->guard]);
                }
                // created = written this run; would-create = planned (dry-run / db unavailable); existing = already there.
                $report->record($section, $isNew ? ($canWrite ? 'created' : 'would-create') : 'existing', $name);
            }

            $roles = $this->rolesFor($spec);
            if ($canWrite) {
                $this->fileUnderGroup($spec, $names);
                foreach ($this->grant($spec, $names, $roles) as $role) {
                    $report->record($section, 'granted', $role);
                }
            } else {
                foreach ($roles as $role) {
                    $report->record($section, 'would-grant', $role);
                }
            }
        }

        if ($canWrite) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $report;
    }

    /**
     * File the resource's permissions under a "{Plural} Management" group so the Role-edit permission tree
     * stays organised — only when the group-permission feature exists. (Relocated verbatim from the
     * generator; the raw insert fills the public uuid itself because it bypasses the model hook.)
     *
     * @param  list<string>  $names
     */
    private function fileUnderGroup(ResourcePermissionSpec $spec, array $names): void
    {
        if (! Schema::hasTable('group_permissions') || ! Schema::hasColumn('permissions', 'group_id')) {
            return;
        }

        $groupName = $spec->group();
        $groupId = DB::table('group_permissions')->where('name', $groupName)->value('id');
        if (! $groupId) {
            $parentId = DB::table('group_permissions')->where('name', 'All')->value('id');
            $row = [
                'name' => $groupName,
                'parent_id' => $parentId,
                'sort' => (int) DB::table('group_permissions')->where('parent_id', $parentId)->max('sort') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('group_permissions', 'uuid')) {
                $row['uuid'] = (string) Str::uuid7();
            }
            $groupId = DB::table('group_permissions')->insertGetId($row);
        }

        // Only file permissions that aren't already grouped — never move one an admin re-filed into a custom
        // group. This also makes the whole method a no-op once everything is filed (idempotent).
        DB::table('permissions')
            ->whereIn('name', $names)
            ->where('guard_name', $spec->guard)
            ->whereNull('group_id')
            ->update(['group_id' => $groupId]);
    }

    /**
     * Grant $names to each resolved role that exists on the spec's guard (Spatie requires a guard match).
     * `givePermissionTo` is idempotent. Returns the roles actually granted (for the report).
     *
     * @param  list<string>  $names
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function grant(ResourcePermissionSpec $spec, array $names, array $roles): array
    {
        if (! Schema::hasTable('roles')) {
            return [];
        }

        /** @var class-string $roleModel */
        $roleModel = config('admin-core.permission.role_model', \Spatie\Permission\Models\Role::class);
        $granted = [];
        foreach ($roles as $roleName) {
            $role = $roleModel::where('name', $roleName)->where('guard_name', $spec->guard)->first();
            if ($role) {
                $role->givePermissionTo($names);
                $granted[] = $roleName;
            }
        }

        return $granted;
    }

    /**
     * The roles to grant on the spec's guard: the --role override, else `permission.default_roles`, else the
     * per-guard or global super role. A guard-mismatched role simply won't be found in {@see grant()}.
     *
     * @return list<string>
     */
    private function rolesFor(ResourcePermissionSpec $spec): array
    {
        if ($this->rolesOverride !== null) {
            return $this->rolesOverride;
        }

        $configured = config('admin-core.permission.default_roles');
        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_map('strval', $configured)));
        }

        $super = config("admin-core.permission.guards.{$spec->guard}.super_role")
            ?? config('admin-core.permission.super_role', 'admin');

        return $super ? [(string) $super] : [];
    }

    private function permissionsTable(): string
    {
        /** @var class-string $permModel */
        $permModel = config('admin-core.permission.model', \Spatie\Permission\Models\Permission::class);

        return (new $permModel)->getTable();
    }
}
