<?php

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Schema;
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

it('persists the chosen locale to the signed-in user (durable across devices) and prefers it on resolve', function () {
    Schema::create('loc_users', function ($t) {
        $t->id();
        $t->string('locale')->nullable();
    });
    $userModel = new class extends \Illuminate\Foundation\Auth\User
    {
        protected $table = 'loc_users';
        public $timestamps = false;
        protected $guarded = [];
    };
    $user = $userModel::create(['locale' => null]);

    // ?setlang=km on an authenticated request → applied AND written back to the user's locale column.
    $req = Request::create('/admin?setlang=km', 'GET');
    $req->setLaravelSession(app('session.store'));
    $req->setUserResolver(fn () => $user);
    (new SetLocale)->handle($req, fn ($r) => response('ok'));
    expect(app()->getLocale())->toBe('km')
        ->and($user->fresh()->locale)->toBe('km'); // durable per-user

    // resolve() prefers the stored user locale over a different session value.
    $req2 = Request::create('/admin', 'GET');
    $req2->setLaravelSession(app('session.store'));
    $req2->session()->put('admin-core.locale', 'en'); // session says en…
    $req2->setUserResolver(fn () => $user->fresh());   // …but the user says km
    (new SetLocale)->handle($req2, fn ($r) => response('ok'));
    expect(app()->getLocale())->toBe('km');

    Schema::dropIfExists('loc_users');
});

it('caps outbound translate() calls per request at the rate_limit budget', function () {
    config()->set('admin-core.translation.locales', ['en' => 'EN', 'km' => 'KM', 'fr' => 'FR', 'th' => 'TH']);
    config()->set('admin-core.translation.rate_limit', 2);
    app()->setLocale('en');

    $translator = new class implements Translator
    {
        public int $calls = 0;

        public function translate(string $text, string $from, string $to): string
        {
            $this->calls++;

            return "[{$to}] {$text}";
        }
    };
    app()->instance(Translator::class, $translator);

    // One field, source 'en' filled, 3 locales blank → 3 translations wanted, but the budget caps it.
    $request = Request::create('/save', 'POST', [
        '_translate' => ['name'],
        'name' => ['en' => 'Hello', 'km' => '', 'fr' => '', 'th' => ''],
    ]);
    runAutoTranslate($request);

    // No more than the budget of outbound calls; so at least one locale is left untranslated.
    expect($translator->calls)->toBeLessThanOrEqual(2)->toBeGreaterThan(0);
    $filled = collect(['km', 'fr', 'th'])->filter(fn ($l) => $request->input("name.{$l}") !== '')->count();
    expect($filled)->toBeLessThanOrEqual(2);
});
