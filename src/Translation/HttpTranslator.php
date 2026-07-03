<?php

namespace Ngos\AdminCore\Translation;

use Throwable;

/**
 * Shared guards for HTTP-backed drivers. translate() short-circuits the cases a provider call would
 * waste (empty text, same source/target, over the length cap) and wraps the actual call so any failure
 * — network, timeout, bad response — falls back to the original text instead of throwing. Concrete
 * drivers only implement {@see fetch()}.
 */
abstract class HttpTranslator implements Translator
{
    /** Whether the LAST translate() call fell back to the source because it COULDN'T translate. */
    private bool $failed = false;

    public function translate(string $text, string $from, string $to): string
    {
        $this->failed = false;
        $text = trim($text);

        if ($text === '' || $from === $to) {
            return $text; // nothing to translate — the source IS the correct value (not a failure)
        }

        if (mb_strlen($text) > (int) config('admin-core.translation.max_length', 5000)) {
            $this->failed = true; // too long — a retryable non-result, don't ship oversized payloads

            return $text;
        }

        try {
            $translated = $this->fetch($text, $from, $to);
            if (trim($translated) === '') {
                $this->failed = true;

                return $text;
            }

            return $translated;
        } catch (Throwable) {
            $this->failed = true; // fail safe: a translation outage must never break a save

            return $text;
        }
    }

    /**
     * Did the LAST translate() call fall back to the source because it couldn't translate (outage / quota /
     * oversized / empty result) — as opposed to a real result that happens to equal the source (an identity
     * translation like 'OK'→'OK')? The batch command uses this to skip failures (retry) while still
     * persisting genuine identity translations (converge).
     */
    public function failed(): bool
    {
        return $this->failed;
    }

    /** Seconds to wait on the provider before giving up. */
    protected function timeout(): int
    {
        return (int) config('admin-core.translation.timeout', 8);
    }

    /** Call the provider and return the translated string. May throw — translate() catches it. */
    abstract protected function fetch(string $text, string $from, string $to): string;
}
