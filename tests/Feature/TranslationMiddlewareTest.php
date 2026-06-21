<?php

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Ngos\AdminCore\Http\Middleware\AutoTranslate;
use Ngos\AdminCore\Http\Middleware\SetLocale;
use Ngos\AdminCore\Translation\Translator;

/*
 * The two localization middlewares: AutoTranslate (fills empty per-locale fields on save, no endpoint)
 * and SetLocale (per-user UI language with ?setlang switching).
 */

beforeEach(function () {
    config()->set('admin-core.translation.enabled', true);
    config()->set('admin-core.translation.locales', ['en' => 'English', 'km' => 'ខ្មែរ']);
    config()->set('admin-core.translation.default', 'en');

    // A predictable fake translator so we can assert what gets filled.
    app()->bind(Translator::class, fn () => new class implements Translator
    {
        public function translate(string $text, string $from, string $to): string
        {
            return "[{$to}] {$text}";
        }
    });
});

function runAutoTranslate(Request $request): Request
{
    (new AutoTranslate)->handle($request, fn ($r) => response("ok"));

    return $request;
}

it('fills empty locales from the one the user filled, and strips the marker', function () {
    app()->setLocale('en');
    $request = Request::create('/save', 'POST', [
        '_translate' => ['name'],
        'name' => ['en' => '', 'km' => 'សួស្ដី'],
    ]);

    runAutoTranslate($request);

    expect($request->input('name.km'))->toBe('សួស្ដី')        // source untouched
        ->and($request->input('name.en'))->toBe('[en] សួស្ដី') // empty locale filled
        ->and($request->has('_translate'))->toBeFalse();        // marker removed
});

it('never overwrites a locale the user already typed', function () {
    app()->setLocale('en');
    $request = Request::create('/save', 'POST', [
        '_translate' => ['name'],
        'name' => ['en' => 'Hello', 'km' => 'សួស្ដី'],
    ]);

    runAutoTranslate($request);

    expect($request->input('name.en'))->toBe('Hello')
        ->and($request->input('name.km'))->toBe('សួស្ដី');
});

it('does nothing when auto-translate is disabled (UI language still works)', function () {
    config()->set('admin-core.translation.enabled', false);
    $request = Request::create('/save', 'POST', [
        '_translate' => ['name'],
        'name' => ['en' => '', 'km' => 'សួស្ដី'],
    ]);

    runAutoTranslate($request);

    expect($request->input('name.en'))->toBe('')          // not filled
        ->and($request->has('_translate'))->toBeFalse();  // marker still stripped
});

it('switches the locale via ?setlang and remembers it in the session', function () {
    $request = Request::create('/admin/dashboard?setlang=km', 'GET');
    $request->setLaravelSession(app('session.store'));

    (new SetLocale)->handle($request, fn ($r) => response("ok"));

    expect(app()->getLocale())->toBe('km')
        ->and($request->session()->get('admin-core.locale'))->toBe('km');
});

it('resolves the locale from the session, then falls back to the configured default', function () {
    // From session:
    $request = Request::create('/admin', 'GET');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('admin-core.locale', 'km');
    (new SetLocale)->handle($request, fn ($r) => response("ok"));
    expect(app()->getLocale())->toBe('km');

    // Default when nothing is set (a fresh, empty session):
    $fresh = Request::create('/admin', 'GET');
    $fresh->setLaravelSession(new Store('t', new ArraySessionHandler(120)));
    (new SetLocale)->handle($fresh, fn ($r) => response("ok"));
    expect(app()->getLocale())->toBe('en');
});

it('ignores an unsupported ?setlang value', function () {
    $request = Request::create('/admin?setlang=zz', 'GET');
    $request->setLaravelSession(app('session.store'));

    (new SetLocale)->handle($request, fn ($r) => response("ok"));

    expect(app()->getLocale())->toBe('en'); // fell back to default, not 'zz'
});
