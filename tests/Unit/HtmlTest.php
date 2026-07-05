<?php

use Ngos\AdminCore\Support\Html;

it('strips script / style / iframe elements but keeps safe markup', function () {
    expect(Html::clean('<p>ok</p><script>alert(1)</script>'))->toBe('<p>ok</p>');
    expect(Html::clean('<style>x{}</style><b>keep</b>'))->toBe('<b>keep</b>');
    expect(Html::clean('<iframe src="x"></iframe><b>keep</b>'))->toContain('<b>keep</b>')->not->toContain('iframe');
});

it('strips inline event handlers and javascript:/data: URLs', function () {
    expect(Html::clean('<a href="javascript:alert(1)">x</a>'))->not->toContain('javascript:');
    expect(Html::clean('<img src=x onerror="alert(1)">'))->not->toContain('onerror');
    expect(Html::clean("<div onclick='x'>hi</div>"))->not->toContain('onclick')->toContain('hi');
});

it('strips a slash-separated event handler (<svg/onload=…>), not just whitespace-separated ones', function () {
    expect(Html::clean('<svg/onload=alert(1)>'))->not->toContain('onload');
    expect(Html::clean('<img/onerror="alert(1)" src=x>'))->not->toContain('onerror');
    expect(Html::clean('<svg/onload=alert(1)>'))->not->toContain('alert'); // the value goes too
});

it('strips a javascript: URL obfuscated by HTML entities or embedded whitespace (browser still decodes it)', function () {
    // A browser decodes HTML entities and ignores whitespace/control chars in a URL scheme before dispatching
    // it — so these all execute despite not containing the literal substring "javascript".
    $entity = Html::clean('<a href="j&#97;vascript:alert(document.cookie)">click</a>');
    expect(strtolower(preg_replace('/[\s\x00-\x20]+/', '', html_entity_decode($entity))))->not->toContain('javascript:');

    $whitespace = Html::clean("<a href=\"java\tscript:alert(1)\">click</a>");
    expect(strtolower(preg_replace('/[\s\x00-\x20]+/', '', $whitespace)))->not->toContain('javascript:');

    // vbscript: and data: obfuscations too.
    expect(strtolower(preg_replace('/\s+/', '', Html::clean('<a href="vb&#115;cript:x">y</a>'))))->not->toContain('vbscript:');
    expect(Html::clean('<img src="data:text/html,<b>x">'))->not->toContain('data:');
});

it('keeps ordinary rich text and passes null/empty through', function () {
    $safe = Html::clean('<p><strong>Bold</strong> <a href="/page">link</a></p>');
    expect($safe)->toContain('<strong>Bold</strong>')->toContain('href="/page"');
    expect(Html::clean(null))->toBeNull();
    expect(Html::clean(''))->toBe('');
});

it('strips a quote-adjacent event handler the old blocklist regex missed (audit-11 XSS)', function () {
    // <img src="x"onerror=…> — the closing quote of src is the boundary before `onerror`, so a `[\s/]on…`
    // regex never matched it. The allowlist drops onerror because it isn't an allowed attribute at all.
    $out = Html::clean('<img src="x"onerror="alert(document.cookie)">');
    expect($out)->not->toContain('onerror')->not->toContain('alert');
});

it('strips a slash-separated javascript: URL the old blocklist regex missed (audit-11 XSS)', function () {
    // <a/href="javascript:…"> — the `/` before href isn't `\s`, so the old URL-scheme regex skipped it.
    $out = Html::clean('<a/href="javascript:alert(document.cookie)">click</a>');
    expect($out)->not->toContain('javascript')->toContain('click'); // scheme dropped, text kept
});

it('drops any non-allowlisted attribute (on* handlers, framework directives, id) structurally', function () {
    // The allowlist means novel event/handler/directive sinks are dropped without needing a pattern for each.
    $out = Html::clean('<p x-on:click="hack()" @click="hack()" onmouseover="hack()" id="sink" tabindex="1">hi</p>');
    expect($out)->toContain('hi')
        ->not->toContain('x-on')->not->toContain('@click')->not->toContain('onmouseover')
        ->not->toContain('id=')->not->toContain('tabindex');
});

it('drops a non-allowlisted element but keeps its (sanitized) text content', function () {
    $out = Html::clean('<section><font color="red">kept text</font></section>');
    expect($out)->toContain('kept text')->not->toContain('<section')->not->toContain('<font');
});

it('keeps a safe inline style but drops a dangerous one (url()/expression)', function () {
    expect(Html::clean('<p style="text-align:center;color:#333">ok</p>'))->toContain('text-align:center');
    expect(Html::clean('<p style="width:expression(alert(1))">x</p>'))->not->toContain('expression')->toContain('x');
    expect(Html::clean('<p style="background:url(javascript:alert(1))">y</p>'))->not->toContain('javascript')->toContain('y');
});

it('preserves multibyte (Khmer) content through the DOM round-trip', function () {
    $out = Html::clean('<p>ខ្មែរ <strong>test</strong></p>');
    expect($out)->toContain('ខ្មែរ')->toContain('<strong>test</strong>');
});

it('exposes safeUrl/isSafeUrl for href sinks (menu links, redirects)', function () {
    // Safe: permitted schemes + relative/anchor.
    foreach (['https://x.test/y', 'http://x', 'mailto:a@b.test', 'tel:+123', '/panel', '#top', '?q=1', 'page.html', ''] as $ok) {
        expect(Html::isSafeUrl($ok))->toBeTrue("expected '{$ok}' safe");
    }
    // Dangerous: script schemes, including obfuscated (browser decodes entities + strips whitespace/case).
    foreach (['javascript:alert(1)', 'JAVASCRIPT:x', "java\tscript:x", 'vbscript:x', 'data:text/html,x', 'j&#97;vascript:x'] as $bad) {
        expect(Html::isSafeUrl($bad))->toBeFalse("expected '{$bad}' dangerous");
        expect(Html::safeUrl($bad))->toBe('#'); // neutralised to a harmless anchor
    }
    expect(Html::safeUrl('https://x.test'))->toBe('https://x.test') // safe url passes through
        ->and(Html::safeUrl(null))->toBe('#');
});

it('neutralises a javascript: url in a menu item at render (Menu manager stored XSS)', function () {
    // menu_items.url is admin-supplied; Blade {{ }} escapes HTML entities but not the javascript: scheme, so the
    // sidebar render must route it through Html::safeUrl (covers already-stored rows, not just new validation).
    $items = [
        ['label' => 'Bad', 'url' => 'javascript:alert(document.cookie)'],
        ['label' => 'Good', 'url' => 'https://example.test/page'],
    ];
    $html = \Illuminate\Support\Facades\Blade::render('<x-admin-core::sidebar-menu :items="$items" />', compact('items'));

    expect($html)->not->toContain('javascript:')      // dangerous scheme dropped to href="#"
        ->toContain('href="#"')
        ->toContain('https://example.test/page');     // safe url kept
});
