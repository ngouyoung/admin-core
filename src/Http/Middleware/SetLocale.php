<?php

namespace Ngos\AdminCore\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-user UI language. Resolves the active locale and applies it with App::setLocale(), so one admin
 * can run the panel in English while another runs it in Khmer — each remembered.
 *
 * Switching needs no endpoint: a `?setlang=km` link (only accepted for a configured locale) is picked up
 * here, persisted, and used. Resolution order: an incoming ?setlang → the signed-in user's stored
 * `locale` (when a `users.locale` column exists — durable across devices) → the session → the configured
 * default. Writing back to the user column is best-effort and skipped when the column isn't there.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys((array) config('admin-core.translation.locales', []));

        if ($available === []) {
            return $next($request);
        }

        $switch = $request->query('setlang');

        if (is_string($switch) && in_array($switch, $available, true)) {
            $this->remember($request, $switch);
        }

        $locale = $this->resolve($request, $available);
        app()->setLocale($locale);

        return $next($request);
    }

    /**
     * @param  array<int, string>  $available
     */
    protected function resolve(Request $request, array $available): string
    {
        $user = $this->authenticatedUser($request);

        if ($user instanceof Model && $this->modelHasLocaleColumn($user)) {
            $locale = $user->getAttribute('locale');

            if (is_string($locale) && in_array($locale, $available, true)) {
                return $locale;
            }
        }

        $session = $request->hasSession() ? $request->session()->get('admin-core.locale') : null;

        if (is_string($session) && in_array($session, $available, true)) {
            return $session;
        }

        $default = (string) config('admin-core.translation.default', config('app.locale', 'en'));

        return in_array($default, $available, true) ? $default : $available[0];
    }

    protected function remember(Request $request, string $locale): void
    {
        if ($request->hasSession()) {
            $request->session()->put('admin-core.locale', $locale);
        }

        $user = $this->authenticatedUser($request);

        if ($user instanceof Model && $this->modelHasLocaleColumn($user)) {
            $user->forceFill(['locale' => $locale])->save();
        }
    }

    /**
     * The signed-in user across the default guard AND any configured portal guard — so a portal user (on a
     * NON-default guard) has their stored locale read AND persisted to the RIGHT account. $request->user()
     * reads only the default guard, so in a multi-guard app it would miss a portal user's stored locale and
     * (on ?setlang) write the switch to nobody. Mirrors AutoTranslate's guard-aware resolution.
     */
    protected function authenticatedUser(Request $request): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        // Delegated to the single canonical resolver (AR-1) — config-guard-based, portal-first, identical to
        // every other locus. RATIFIED SCOPE (WP-B13b, Decision D1=b): the locale user is resolved by CONFIGURED
        // GUARD ONLY. A user present solely via Laravel's per-request user-resolver ($request->setUserResolver /
        // a token guard) with NO configured guard authenticated is deliberately NOT consulted here — their
        // stored locale is not read and a ?setlang is not written back to them. This is the accepted, pinned
        // drop (see the "request-resolver-only user is NOT consulted" test); the former default-guard
        // request-resolver path is intentionally gone and no fallback lives in any locus. $request is retained
        // for the signature and hasSession()/session() reads above.
        return \Ngos\AdminCore\Support\ActorResolver::user();
    }

    /**
     * Whether the user table has a `locale` column (else we degrade to session-only). Cached two ways:
     * a per-process static (fast, no driver call on repeat), backed by a day-TTL cache so the schema is
     * probed at most ~once/day across the whole fleet — and the TTL means a later migration that adds the
     * column is picked up automatically (no permanent stale `false`).
     */
    protected function modelHasLocaleColumn(Model $user): bool
    {
        static $has = [];

        $table = $user->getTable();

        return $has[$table] ??= Cache::remember(
            "admin-core:has-locale-column:{$table}",
            now()->addDay(),
            fn () => Schema::hasColumn($table, 'locale'),
        );
    }
}
