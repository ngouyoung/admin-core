<?php

namespace Ngos\AdminCore\Support;

class Html
{
    /**
     * Defense-in-depth sanitizer for rich-text (CKEditor) HTML, applied before it is stored and later
     * echoed raw on the show page. It removes the script-y vectors a WYSIWYG never legitimately emits:
     *   - <script>/<style>/<iframe>/<object>/<embed>/<form>/<link>/<meta>/<base> elements,
     *   - inline event-handler attributes (on*="…"),
     *   - javascript:/vbscript:/data: URLs in href/src.
     *
     * This is NOT a complete HTML sanitizer. For genuinely untrusted input, install a dedicated library
     * (e.g. ezyang/htmlpurifier) and call it here instead.
     */
    public static function clean(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        // Dangerous elements — drop the tag and (for script/style) its content.
        $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta|base)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = preg_replace('#</?(script|style|iframe|object|embed|form|link|meta|base)\b[^>]*>#is', '', $html);

        // Inline event handlers: on*="…" / on*='…' / on*=value. The separator before the handler may be
        // whitespace OR a slash (`<svg/onload=…>` is a valid, executable variant), so match both.
        $html = preg_replace('#[\s/]on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html);

        // javascript:/vbscript:/data: URLs in href/src/xlink:href
        $html = preg_replace('#\s(href|src|xlink:href)\s*=\s*("|\')\s*(?:javascript|vbscript|data)\s*:[^"\']*\2#is', '', $html);

        return $html;
    }
}
