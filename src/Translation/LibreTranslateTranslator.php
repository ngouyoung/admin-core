<?php

namespace Ngos\AdminCore\Translation;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * LibreTranslate (https://libretranslate.com) — open-source. Point `translation.libretranslate.url` at
 * your own self-hosted instance (Docker) so text never leaves your servers — the private/secure choice.
 * Public instances usually need an `api_key`. Translation quality for some languages (e.g. Khmer) is
 * model-dependent, so review the output.
 */
class LibreTranslateTranslator extends HttpTranslator
{
    protected function fetch(string $text, string $from, string $to): string
    {
        $url = rtrim((string) config('admin-core.translation.libretranslate.url', 'https://libretranslate.com'), '/');

        $payload = [
            'q' => $text,
            'source' => $from,
            'target' => $to,
            'format' => 'text',
        ];

        if ($key = config('admin-core.translation.libretranslate.key')) {
            $payload['api_key'] = $key;
        }

        $response = Http::timeout($this->timeout())
            ->asForm()
            ->acceptJson()
            ->post("{$url}/translate", $payload)
            ->throw()
            ->json();

        $translated = $response['translatedText'] ?? null;

        if (! is_string($translated) || $translated === '') {
            throw new RuntimeException('LibreTranslate returned no translation.');
        }

        return $translated;
    }
}
