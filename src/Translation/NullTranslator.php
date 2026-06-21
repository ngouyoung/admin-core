<?php

namespace Ngos\AdminCore\Translation;

/**
 * No-op driver: returns the text unchanged. Selected by config('admin-core.translation.driver') = 'null'
 * (or used in tests). With this driver the per-user UI language still works — only auto-translate is off.
 */
class NullTranslator implements Translator
{
    public function translate(string $text, string $from, string $to): string
    {
        return $text;
    }
}
