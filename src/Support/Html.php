<?php

namespace Ngos\AdminCore\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;

class Html
{
    /**
     * Allowlist sanitizer for rich-text (CKEditor) HTML, applied before it is stored and later echoed raw on the
     * show page ({!! !!}). It parses the HTML with the DOM and keeps ONLY an allowlist of tags, attributes and
     * URL schemes — so anything not explicitly permitted (every on* handler, <script>/<iframe>/…, a javascript:
     * URL, an x-on:/@click framework directive, a data: URL) is dropped structurally rather than pattern-matched.
     * That closes the whole class of blocklist-regex bypasses (a quote- or slash-adjacent attribute, an
     * entity-obfuscated scheme, mutation-XSS) instead of playing whack-a-mole.
     *
     * It is intentionally strict; for exotic embeds (oEmbed, custom widgets) widen {@see ALLOWED_TAGS}/
     * {@see ALLOWED_ATTRS} deliberately, or drop in a dedicated library (e.g. ezyang/htmlpurifier).
     */
    /** @var array<int, string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins', 'sub', 'sup',
        'mark', 'small', 'abbr', 'cite', 'q', 'kbd', 'samp', 'var', 'wbr', 'address', 'time',
        'blockquote', 'pre', 'code', 'span', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'a', 'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
    ];

    /**
     * Attribute allowlist. '*' applies to every allowed tag; a per-tag entry adds to it. Deliberately NO on*
     * handlers, NO framework directives, NO id (CSS/JS targeting) — only presentational + link/image basics.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_ATTRS = [
        '*' => ['class', 'title', 'dir', 'lang', 'style'],
        'a' => ['href', 'target', 'rel', 'name'],
        'img' => ['src', 'alt', 'width', 'height'],
        'td' => ['colspan', 'rowspan', 'headers'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'ol' => ['start', 'type', 'reversed'],
        'col' => ['span'],
        'colgroup' => ['span'],
        'time' => ['datetime'],
    ];

    /** Attributes carrying a URL — the scheme must pass {@see schemeIsSafe}. */
    private const URL_ATTRS = ['href', 'src'];

    /** URL schemes permitted in href/src. Everything else (javascript:, vbscript:, data:, …) is dropped. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel', 'ftp'];

    public static function clean(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        $dom = new DOMDocument;
        $useErrors = libxml_use_internal_errors(true);
        // Parse as a UTF-8 fragment: a processing instruction pins the encoding (libxml defaults to Latin-1, which
        // would mangle multibyte e.g. Khmer), and a known-id wrapper lets us serialize just the fragment back.
        // NOIMPLIED + NODEFDTD stop libxml adding <!DOCTYPE>/<html>/<body> around it.
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__ac_root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($useErrors);

        $root = $dom->getElementById('__ac_root__');
        if ($root === null) {
            return '';
        }

        self::sanitizeChildren($root);

        // Serialize the wrapper's CHILDREN, not the wrapper itself.
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * Whether a user-supplied URL is safe to place in an href/src sink — a permitted scheme
     * (http/https/mailto/tel/ftp) or a relative/anchor/query URL. Empty counts as safe (nothing to dispatch).
     * Blade's `{{ }}` escapes HTML entities but NOT a `javascript:`/`vbscript:`/`data:` scheme, so any value
     * echoed into a link (a menu url, a stored redirect) must be checked with this first.
     */
    public static function isSafeUrl(?string $url): bool
    {
        $url = trim((string) $url);

        return $url === '' || self::schemeIsSafe($url);
    }

    /** The URL when {@see isSafeUrl}, else '#' — a drop-in for a raw href value in a Blade/template sink. */
    public static function safeUrl(?string $url): string
    {
        $url = trim((string) $url);

        return $url !== '' && self::schemeIsSafe($url) ? $url : '#';
    }

    /** Recursively sanitize a node's children in place (iterate a snapshot — the tree is mutated). */
    private static function sanitizeChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);
                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    self::dropElement($child, $tag);

                    continue;
                }
                self::sanitizeAttributes($child, $tag);
                self::sanitizeChildren($child);
            } elseif ($child instanceof DOMComment) {
                // Comments can carry IE conditional payloads (<!--[if]><script>…) — remove them.
                $child->parentNode?->removeChild($child);
            }
            // Text nodes are kept verbatim; saveHTML() re-encodes them safely.
        }
    }

    /**
     * Drop a disallowed element. For script/style the text content is CODE, so remove the whole subtree;
     * otherwise UNWRAP — keep the (sanitized) children so a stray <font>/<section> doesn't erase real content.
     */
    private static function dropElement(DOMElement $el, string $tag): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        if (in_array($tag, ['script', 'style', 'template', 'noscript'], true)) {
            $parent->removeChild($el);

            return;
        }
        self::sanitizeChildren($el);
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    /** Strip every attribute not on the allowlist, plus any URL/style attribute whose value is dangerous. */
    private static function sanitizeAttributes(DOMElement $el, string $tag): void
    {
        $allowed = array_merge(self::ALLOWED_ATTRS['*'], self::ALLOWED_ATTRS[$tag] ?? []);
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            if (! in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->nodeName); // drops every on* handler, x-*/@* directive, id, …

                continue;
            }
            if (in_array($name, self::URL_ATTRS, true) && ! self::schemeIsSafe((string) $attr->nodeValue)) {
                $el->removeAttribute($attr->nodeName);
            } elseif ($name === 'style' && ! self::styleIsSafe((string) $attr->nodeValue)) {
                $el->removeAttribute($attr->nodeName);
            }
        }
        // target=_blank without rel=noopener is a reverse-tabnabbing vector — pin it.
        if ($tag === 'a' && strtolower((string) $el->getAttribute('target')) === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /** A URL scheme is safe when it is relative/anchor, or a scheme on the allowlist (browser-normalised first). */
    private static function schemeIsSafe(string $value): bool
    {
        // Normalise the way a browser does before dispatching: decode entities, strip whitespace/control chars.
        $normalised = strtolower((string) preg_replace('/[\x00-\x20\s]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5)));
        if ($normalised === '' || in_array($normalised[0], ['/', '#', '?'], true)
            || str_starts_with($normalised, './') || str_starts_with($normalised, '../')) {
            return true; // relative / anchor / query — no scheme
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $normalised, $m)) {
            return in_array($m[1], self::SAFE_SCHEMES, true);
        }

        return true; // a bare relative path (page.html) — no scheme, safe
    }

    /** An inline style is safe when it carries none of the CSS-based execution/exfil vectors. */
    private static function styleIsSafe(string $value): bool
    {
        // Block IE expression()/behavior, -moz-binding, @import, and url() wholesale (a CSS exfil / js: sink).
        return ! preg_match(
            '/expression|javascript:|vbscript:|behaviou?r|-moz-binding|@import|url\s*\(/i',
            html_entity_decode($value, ENT_QUOTES | ENT_HTML5),
        );
    }
}
