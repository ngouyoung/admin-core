<?php

namespace Ngos\AdminCore\Services\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Least-privilege guard for the access kit: an actor may only hand out authority they already HOLD.
 *
 * Without it, `edit-role` alone lets an account sync EVERY permission onto its own role, and `edit-user`
 * alone lets it assign the super role to itself — a self-service privilege escalation from two narrow,
 * plausible "account management" grants. Only the ADDED permissions/roles are checked (the new set minus
 * what the record already has), so renaming a role — which resubmits its current grants — never breaks
 * for an editor who couldn't have granted them originally.
 *
 * A super-role holder is unrestricted, and a console context (seeder, command — no authenticated actor)
 * is exempt: the guard is about web-request grants, not provisioning.
 */
trait GrantsWithinOwnAuthority
{
    /**
     * Refuse a permission grant the acting user doesn't hold.
     *
     * @param  Collection<int, \Spatie\Permission\Contracts\Permission>  $permissions  the record's NEW (full) permission set
     * @param  iterable<int, int|string>  $currentIds  the record's current permission ids (added = new − current)
     *
     * @throws AuthorizationException
     */
    protected function assertCanGrantPermissions(Collection $permissions, iterable $currentIds): void
    {
        $actor = auth()->user();
        if ($actor === null || $this->grantActorIsSuper($actor)) {
            return;
        }

        $current = collect($currentIds)->map(fn ($id) => (int) $id)->all();
        $denied = $permissions
            ->reject(fn ($p) => in_array((int) $p->getKey(), $current, true)) // only newly ADDED grants
            ->reject(fn ($p) => method_exists($actor, 'hasPermissionTo') && $actor->hasPermissionTo($p))
            ->pluck('name');

        if ($denied->isNotEmpty()) {
            throw new AuthorizationException(
                __('You can only grant permissions you hold yourself. Not yours to grant: :names', ['names' => $denied->implode(', ')]),
            );
        }
    }

    /**
     * Refuse a role assignment beyond the acting user's own authority: the super role only from a super
     * holder (it usually carries NO explicit permissions — a Gate::before bypass — so the subset check
     * below can't protect it), and any other role only when the actor holds every permission it carries.
     *
     * @param  Collection<int, \Spatie\Permission\Contracts\Role>  $roles  the record's NEW (full) role set
     * @param  iterable<int, int|string>  $currentIds  the record's current role ids (added = new − current)
     *
     * @throws AuthorizationException
     */
    protected function assertCanGrantRoles(Collection $roles, iterable $currentIds): void
    {
        $actor = auth()->user();
        if ($actor === null || $this->grantActorIsSuper($actor)) {
            return;
        }

        $current = collect($currentIds)->map(fn ($id) => (int) $id)->all();
        $super = $this->grantSuperRoleName();

        foreach ($roles as $role) {
            if (in_array((int) $role->getKey(), $current, true)) {
                continue; // already assigned — resubmitting it isn't a new grant
            }
            if ($super !== '' && $role->name === $super) {
                throw new AuthorizationException(
                    __('Only a holder of the :role role can assign it.', ['role' => $super]),
                );
            }
            $missing = $role->permissions
                ->reject(fn ($p) => method_exists($actor, 'hasPermissionTo') && $actor->hasPermissionTo($p))
                ->pluck('name');
            if ($missing->isNotEmpty()) {
                throw new AuthorizationException(
                    __('You can only assign roles whose permissions you hold yourself. Not yours to grant: :names', ['names' => $missing->implode(', ')]),
                );
            }
        }
    }

    /** Whether the actor holds the (guard-aware) super role — unrestricted grants. */
    private function grantActorIsSuper(object $actor): bool
    {
        $super = $this->grantSuperRoleName();

        return $super !== '' && method_exists($actor, 'hasRole') && $actor->hasRole($super);
    }

    /** The super role for the ACTIVE guard (portals name their own), falling back to the global one. */
    private function grantSuperRoleName(): string
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        return (string) (config("admin-core.permission.guards.{$guard}.super_role")
            ?? config('admin-core.permission.super_role', 'admin'));
    }
}
