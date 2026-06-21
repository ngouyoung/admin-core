<?php

namespace Ngos\AdminCore\Translation;

/**
 * A translation backend. Implementations call a provider (MyMemory, LibreTranslate, …) to turn
 * `$text` from one language into another. Resolve the configured one via the container —
 * `app(Translator::class)` — never `new` a concrete driver, so config('admin-core.translation.driver')
 * stays the single switch.
 */
interface Translator
{
    /**
     * Translate `$text` from `$from` to `$to` (ISO codes, e.g. 'km', 'en').
     *
     * Must be lossless-on-failure: if the provider errors, times out, or the text is empty/over the
     * length cap, return the original `$text` unchanged rather than throwing — a save must never fail
     * because a translation service was down.
     */
    public function translate(string $text, string $from, string $to): string;
}
