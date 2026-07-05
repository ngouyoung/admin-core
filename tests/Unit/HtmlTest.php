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
