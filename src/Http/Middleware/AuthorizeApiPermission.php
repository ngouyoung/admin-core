<?php

namespace Ngos\AdminCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorize an API route by a permission resolved on the guard the resource's permissions live on
 * (the back-office permission guard — `web` by default), not on the request's auth guard.
 *
 * The API authenticates through its own guard (Passport's `api`, Sanctum, …), but the seeded admin's
 * permissions are scoped to the `web` guard. Spatie's stock `permission:` middleware resolves the
 * permission on the *active* guard, so a web admin calling a Passport-secured route is forbidden even
 * though they hold the permission. This bridges the two — same person, same permissions, web + API.
 *
 * Generated `--api` routes use this; a `--guard=api` resource passes that guard explicitly, keeping the
 * multi-portal model intact.
 */
class AuthorizeApiPermission
{
    public function handle(Request $request, Closure $next, string $permission, ?string $guard = null): Response
    {
        if ($guard === null) {
            $configured = config('admin-core.permission.guard', config('auth.defaults.guard', 'web'));
            $guard = is_string($configured) ? $configured : 'web';
        }

        $user = $request->user();

        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        if (! method_exists($user, 'hasPermissionTo')) {
            throw UnauthorizedException::missingTraitHasRoles($user);
        }

        if (! $user->hasPermissionTo($permission, $guard)) {
            throw UnauthorizedException::forPermissions([$permission]);
        }

        return $next($request);
    }
}
