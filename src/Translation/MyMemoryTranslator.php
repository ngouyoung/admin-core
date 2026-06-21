<?php

namespace Ngos\AdminCore\Translation;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MyMemory (https://mymemory.translated.net) — free, no API key. Anonymous quota is ~5k words/day;
 * setting `translation.mymemory.email` raises it to ~50k. Good enough to draft translations a human
 * then reviews. HTTPS only; the email (if any) is the sole identifier sent.
 */
class MyMemoryTranslator extends HttpTranslator
{
    protected function fetch(string $text, string $from, string $to): string
    {
        $query = [
            'q' => $text,
            'langpair' => "{$from}|{$to}",
        ];

        if ($email = config('admin-core.translation.mymemory.email')) {
            $query['de'] = $email;
        }

        $response = Http::timeout($this->timeout())
            ->acceptJson()
            ->get('https://api.mymemory.translated.net/get', $query)
            ->throw()
            ->json();

        $translated = $response['responseData']['translatedText'] ?? null;

        if (! is_string($translated) || $translated === '') {
            throw new RuntimeException('MyMemory returned no translation.');
        }

        return html_entity_decode($translated, ENT_QUOTES | ENT_HTML5);
    }
}
