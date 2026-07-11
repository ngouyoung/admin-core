<?php

namespace Ngos\AdminCore\Support\Permissions;

/**
 * The single source of truth for CRUD permission names — the `{action}-{resource}` mapping shared by the
 * generator (which bakes the names into stubs), the route macros (which gate on them), and the
 * synchronizer (which creates + grants them). Deriving and reversing names lives here so make and
 * sync-permissions can never disagree.
 */
final class PermissionNaming
{
    /**
     * The CRUD actions `Route::crud` gates and `admin-core:make` seeds. Discovery reverses names against
     * exactly this set, so a custom per-action / transition permission (e.g. `publish-course`, enforced
     * dynamically, not via route middleware) is deliberately NOT treated as a CRUD permission.
     */
    public const CRUD_ACTIONS = ['list', 'create', 'edit', 'delete'];

    /** Build the permission name for an action on a kebab resource, honouring the configured pattern. */
    public static function name(string $action, string $resource): string
    {
        return str_replace(
            ['{action}', '{resource}'],
            [$action, $resource],
            (string) config('admin-core.permission.pattern', '{action}-{resource}'),
        );
    }

    /**
     * Reverse a permission name into [action, resource] using the configured pattern and the known CRUD
     * action set — or null when it isn't a CRUD permission. Pattern-agnostic: it derives the literal
     * affixes around `{resource}` for each action, so `{action}-{resource}` and `{resource}.{action}`
     * both parse. `list-course-review` → [list, course-review] (the action set disambiguates the hyphens).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $permission): ?array
    {
        foreach (self::CRUD_ACTIONS as $action) {
            $template = self::name($action, "\0");
            if (! str_contains($template, "\0")) {
                continue; // pattern has no {resource} placeholder — not reversible
            }
            [$prefix, $suffix] = explode("\0", $template, 2);

            if ($prefix !== '' && ! str_starts_with($permission, $prefix)) {
                continue;
            }
            if ($suffix !== '' && ! str_ends_with($permission, $suffix)) {
                continue;
            }

            $resource = substr($permission, strlen($prefix), strlen($permission) - strlen($prefix) - strlen($suffix));
            if ($resource === '') {
                continue;
            }

            return [$action, $resource];
        }

        return null;
    }
}
