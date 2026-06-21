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
    public function translate(string $text, string $from, string $to): string
    {
        $text = trim($text);

        if ($text === '' || $from === $to) {
            return $text;
        }

        if (mb_strlen($text) > (int) config('admin-core.translation.max_length', 5000)) {
            return $text; // too long — don't ship oversized payloads to a free service
        }

        try {
            $translated = $this->fetch($text, $from, $to);

            return trim($translated) !== '' ? $translated : $text;
        } catch (Throwable) {
            return $text; // fail safe: a translation outage must never break a save
        }
    }

    /** Seconds to wait on the provider before giving up. */
    protected function timeout(): int
    {
        return (int) config('admin-core.translation.timeout', 8);
    }

    /** Call the provider and return the translated string. May throw — translate() catches it. */
    abstract protected function fetch(string $text, string $from, string $to): string;
}
