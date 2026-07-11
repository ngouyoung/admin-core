<?php

namespace Ngos\AdminCore\Support\Permissions;

use Illuminate\Routing\Router;

/**
 * Discovers CRUD permission requirements from the RUNTIME SOURCE OF TRUTH: the registered routes.
 *
 * `Route::crud()` gates each action with `permission:{action}-{resource}[,{guard}]` middleware, so the
 * route table already declares every permission the app enforces — no manifest needed, and it stays correct
 * for every resource ever generated. This scans that middleware, reverses each name via
 * {@see PermissionNaming::parse()}, and groups the actions back into one {@see ResourcePermissionSpec} per
 * (guard, resource). Non-CRUD `permission:` middleware (e.g. `manage-media`) and dynamically-enforced
 * action/transition permissions are ignored — they are not part of the generated CRUD surface.
 */
final class RouteResourceDiscovery
{
    public function __construct(private readonly Router $router) {}

    /**
     * @return array<string, ResourcePermissionSpec> keyed by ResourcePermissionSpec::key(), sorted
     */
    public function discover(): array
    {
        $defaultGuard = (string) config('admin-core.permission.guard', 'web');

        /** @var array<string, ResourcePermissionSpec> $specs */
        $specs = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                $argument = substr($middleware, strlen('permission:'));
                if ($argument === '') {
                    continue;
                }

                // `permission:list-region` or, on a portal guard, `permission:list-order,merchant`.
                [$name, $guard] = array_pad(explode(',', $argument, 2), 2, null);
                $name = trim($name);
                $guard = $guard !== null && trim($guard) !== '' ? trim($guard) : $defaultGuard;

                if (str_contains($name, '|')) {
                    continue; // Spatie OR syntax (`permission:list-a|list-b`) — not a single CRUD gate
                }

                $parsed = PermissionNaming::parse($name);
                if ($parsed === null) {
                    continue; // not a CRUD permission (custom action / built-in like manage-media)
                }
                [$action, $resource] = $parsed;

                $spec = new ResourcePermissionSpec($resource, $guard, [$action]);
                $specs[$spec->key()] = isset($specs[$spec->key()])
                    ? $specs[$spec->key()]->withActions([$action])
                    : $spec;
            }
        }

        ksort($specs);

        return $specs;
    }
}
