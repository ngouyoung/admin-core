<?php

namespace Ngos\AdminCore\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The single, business-agnostic resolver of the acting *(user, guard)* for the current request.
 *
 * WHY THIS EXISTS. The question "which configured guard is this request authenticated on, and who is the acting
 * user?" was answered by a hand-rolled `foreach (guards) { try { if (auth()->guard($g)->check()) … } catch {} }`
 * loop copy-pasted across six cross-cutting concerns (audit causer, media attribution, saved-view scoping,
 * dashboard-layout persistence, locale-user, auto-translate gate). The precedence had DRIFTED — the audit path
 * consulted portal guards first while five others consulted the default guard first — so an operator with two
 * simultaneously-active sessions (the default `web` guard AND a portal guard) could have ONE request attributed
 * to TWO identities. This resolver is the one owner of that resolution: one canonical order, one place for the
 * undefined-guard hardening. Every locus routes through it, so one auth state yields exactly one identity.
 *
 * CANONICAL ORDER (portal-first). Configured portal guards (`admin-core.permission.guards`, in configured order)
 * are consulted BEFORE the default guard (`auth.defaults.guard`), de-duplicated — standardised on the order the
 * security-sensitive audit path already used, so a dual-session request attributes to the PORTAL user everywhere.
 *
 * MECHANISM, NOT POLICY. It knows only guard NAMES from config (trusted) — never a domain concept, never an HTTP
 * decision (a caller that wants to 403 an unauthenticated request does so itself). A guard named in admin-core
 * config but absent from `auth.php` is skipped, never fatal — the hardening lives here once.
 */
final class ActorResolver
{
    /**
     * The canonical guard order: configured portal guards first (in configured order), then the default guard,
     * de-duplicated. The single source of iteration order — no caller may define its own.
     *
     * @return list<string>
     */
    public static function guards(): array
    {
        return array_values(array_unique(array_merge(
            array_map('strval', array_keys((array) config('admin-core.permission.guards', []))), // portal guards first
            [(string) config('auth.defaults.guard', 'web')],
        )));
    }

    /**
     * The acting *(user, guard)* under the canonical order — the first guard that has an authenticated user. A
     * guard named in config but not defined in `auth.php` is skipped (never throws).
     *
     * @return array{0: Authenticatable|null, 1: string|null}
     */
    public static function resolve(): array
    {
        foreach (self::guards() as $guard) {
            try {
                $resolved = auth()->guard($guard);
            } catch (\InvalidArgumentException) {
                // A guard named in admin-core config but not defined in auth.php is UNUSABLE — Laravel's
                // AuthManager throws InvalidArgumentException ("Auth guard [x] is not defined" / driver / provider
                // not defined) while CONSTRUCTING the guard. The catch wraps ONLY construction, so skip is confined
                // to that one case. A fault during the actual user lookup below (a DB/provider error, or any
                // exception a custom guard raises from ->user()) is NOT swallowed: it propagates and fails loudly,
                // never silently mis-resolving to a later guard (which, if the faulting guard is the portal guard
                // consulted first, would attribute the request to the wrong identity).
                continue;
            }

            if (($user = $resolved->user()) !== null) {
                return [$user, $guard];
            }
        }

        return [null, null];
    }

    /** The acting user, or null when no configured guard is authenticated. */
    public static function user(): ?Authenticatable
    {
        return self::resolve()[0];
    }

    /** The name of the guard that handled the request, or null when unauthenticated. */
    public static function guard(): ?string
    {
        return self::resolve()[1];
    }

    /**
     * The acting user's identifier + its guard — the shape the media/saved-view/dashboard storage keys use.
     *
     * @return array{0: int|string|null, 1: string|null}
     */
    public static function actor(): array
    {
        [$user, $guard] = self::resolve();

        return [$user?->getAuthIdentifier(), $guard];
    }

    /** Whether any configured guard has an authenticated user. */
    public static function check(): bool
    {
        return self::resolve()[0] !== null;
    }
}
