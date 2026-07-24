<?php

namespace Ngos\AdminCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ngos\AdminCore\Translation\Translator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fills empty per-locale form fields on save — no public endpoint needed. A translatable input renders
 * one value per language (`name[en]`, `name[km]`, …) plus a marker `_translate[]=name`. On submit this
 * middleware, running INSIDE the already-authenticated, CSRF-protected request, takes whichever locale
 * the user filled as the source and translates it into the locales left blank, then hands the completed
 * data to validation + the controller. It never overwrites a value the user typed.
 *
 * Safe to run globally: it no-ops unless the request is a write AND carries a `_translate` marker AND
 * translation is enabled. Outbound calls are capped per request (`translation.rate_limit`).
 */
class AutoTranslate
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldRun($request)) {
            $this->fill($request);
        }

        $request->request->remove('_translate'); // strip the marker so it never reaches validation/DB

        return $next($request);
    }

    protected function shouldRun(Request $request): bool
    {
        return config('admin-core.translation.enabled', true)
            // Only inside an AUTHENTICATED request — this middleware sits on the global `web` group, so without
            // this an anonymous POST (with a CSRF token) to any route + `_translate[]=…` could force up to
            // rate_limit live outbound third-party translate calls, draining the quota and tying up a worker.
            && $this->isAuthenticated()
            && in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)
            && is_array($request->input('_translate'));
    }

    /** Authenticated on the default guard OR any configured portal guard (multi-portal), ignoring undefined guards. */
    protected function isAuthenticated(): bool
    {
        // Delegated to the single canonical resolver (AR-1) — was default-first, now the canonical portal-first.
        return \Ngos\AdminCore\Support\ActorResolver::check();
    }

    protected function fill(Request $request): void
    {
        /** @var array<int, string> $fields */
        $fields = array_values(array_filter((array) $request->input('_translate'), 'is_string'));
        $locales = array_keys((array) config('admin-core.translation.locales', []));
        $budget = (int) config('admin-core.translation.rate_limit', 60);

        if ($fields === [] || count($locales) < 2) {
            return;
        }

        try {
            $translator = app(Translator::class);
        } catch (\Throwable $e) {
            return; // a misconfigured/typo'd translator driver must never 500 a write — just skip auto-fill
        }

        foreach ($fields as $field) {
            // The _translate[] marker carries the field's raw name, which for a repeater-nested translatable
            // field is bracketed (items[0][title]). Request::input()/merge() speak dot notation, not brackets,
            // so without normalising, input('items[0][title]') returns null and the field is silently skipped
            // (no auto-fill, contradicting the documented behaviour). items[0][title] -> items.0.title.
            $dotField = str_replace(['[', ']'], ['.', ''], $field);
            $values = $request->input($dotField);

            if (! is_array($values)) {
                continue; // not a per-locale field group — leave it alone
            }

            [$source, $sourceText] = $this->source($values, $locales);

            if ($source === null) {
                continue; // nothing typed in any language → nothing to translate from
            }

            foreach ($locales as $locale) {
                if ($budget <= 0) {
                    break; // runaway guard tripped — but KEEP what this field already fetched
                }

                if ($locale === $source || $this->filled($values[$locale] ?? null)) {
                    continue; // skip the source and any locale the user already filled
                }

                $values[$locale] = $translator->translate($sourceText, $source, $locale);
                $budget--;
            }

            // Merge BEFORE bailing on an exhausted budget: the translations above were already fetched
            // (quota spent) — discarding them would save nothing and lose real values. data_set writes back
            // into the NESTED structure (items.0.title => …), which merge([$field => …]) can't do for a
            // bracketed name (it would create a literal "items[0][title]" key the form/save never reads).
            $input = $request->all();
            data_set($input, $dotField, $values);
            $request->replace($input);

            if ($budget <= 0) {
                break;
            }
        }
    }

    /**
     * Choose the source locale/value: prefer the app's current locale when filled, else the first
     * non-empty locale in the configured order.
     *
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $locales
     * @return array{0: ?string, 1: string}
     */
    protected function source(array $values, array $locales): array
    {
        $current = app()->getLocale();

        if (in_array($current, $locales, true) && $this->filled($values[$current] ?? null)) {
            return [$current, (string) $values[$current]];
        }

        foreach ($locales as $locale) {
            if ($this->filled($values[$locale] ?? null)) {
                return [$locale, (string) $values[$locale]];
            }
        }

        return [null, ''];
    }

    protected function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
