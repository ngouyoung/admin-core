<?php

namespace Ngos\AdminCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * When 2FA enforcement is on (`admin-core.two_factor.enabled` && `.enforce`), any admin who has not
 * confirmed two-factor authentication is redirected to their profile to set it up — except on the
 * profile routes themselves and logout, so they can finish setup (or sign out) without a redirect loop.
 *
 * A no-op when 2FA is disabled or enforcement is off, so it is safe to register unconditionally.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = config('admin-core.two_factor');
        $enabled = is_array($config) && ($config['enabled'] ?? false) && ($config['enforce'] ?? false);

        if (! $enabled) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user || ! method_exists($user, 'hasConfirmedTwoFactorAuthentication')) {
            return $next($request);
        }

        if ($user->hasConfirmedTwoFactorAuthentication()) {
            return $next($request);
        }

        // Only enforce inside the admin area (route names under the configured prefix), and never on the
        // setup page itself. This leaves guests, the front-end, logout, and the profile pages untouched —
        // no redirect loop, and no lockout for a same-`web`-guard front-end user.
        $prefix = (string) config('admin-core.route.name_prefix', 'admin.');
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;
        if (! is_string($name) || ! str_starts_with($name, $prefix) || str_starts_with($name, $prefix . 'profile.')) {
            return $next($request);
        }

        // If the profile route isn't registered (e.g. enforcement flipped on without the --access kit, or a
        // renamed prefix), degrade to a no-op rather than 500-ing every admin page in a lockout loop.
        $target = $prefix . 'profile.index';
        if (! \Illuminate\Support\Facades\Route::has($target)) {
            return $next($request);
        }

        return redirect()->route($target)
            ->with('warning', __('admin-core::admin-core.auth.2fa_required'));
    }
}
