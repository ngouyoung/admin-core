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

it('keeps ordinary rich text and passes null/empty through', function () {
    $safe = Html::clean('<p><strong>Bold</strong> <a href="/page">link</a></p>');
    expect($safe)->toContain('<strong>Bold</strong>')->toContain('href="/page"');
    expect(Html::clean(null))->toBeNull();
    expect(Html::clean(''))->toBe('');
});
