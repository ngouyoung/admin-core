<?php

namespace Ngos\AdminCore\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
        $user = $request->user();

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

        $user = $request->user();

        if ($user instanceof Model && $this->modelHasLocaleColumn($user)) {
            $user->forceFill(['locale' => $locale])->save();
        }
    }

    /** Cheap, cached check so a missing column degrades to session-only rather than erroring. */
    protected function modelHasLocaleColumn(Model $user): bool
    {
        static $has = [];

        $table = $user->getTable();

        return $has[$table] ??= Schema::hasColumn($table, 'locale');
    }
}
