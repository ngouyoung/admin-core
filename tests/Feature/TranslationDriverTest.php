<?php

use Illuminate\Support\Facades\Http;
use Ngos\AdminCore\Translation\LibreTranslateTranslator;
use Ngos\AdminCore\Translation\MyMemoryTranslator;
use Ngos\AdminCore\Translation\NullTranslator;
use Ngos\AdminCore\Translation\TranslationManager;
use Ngos\AdminCore\Translation\Translator;

/*
 * The driver-based translator: free providers behind one interface, fail-safe so a save never breaks.
 */

it('resolves the configured driver through the manager (and Null when disabled)', function () {
    config()->set('admin-core.translation.enabled', true);

    config()->set('admin-core.translation.driver', 'mymemory');
    expect((new TranslationManager)->driver())->toBeInstanceOf(MyMemoryTranslator::class);

    config()->set('admin-core.translation.driver', 'libretranslate');
    expect((new TranslationManager)->driver())->toBeInstanceOf(LibreTranslateTranslator::class);

    config()->set('admin-core.translation.enabled', false);
    expect((new TranslationManager)->driver())->toBeInstanceOf(NullTranslator::class);
});

it('binds Translator to the configured driver in the container', function () {
    config()->set('admin-core.translation.enabled', true);
    config()->set('admin-core.translation.driver', 'mymemory');

    expect(app(Translator::class))->toBeInstanceOf(MyMemoryTranslator::class);
});

it('translates via MyMemory and decodes entities', function () {
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response([
            'responseData' => ['translatedText' => 'Caf&#233;'],
        ]),
    ]);

    expect((new MyMemoryTranslator)->translate('Cafe', 'en', 'fr'))->toBe('Café');
});

it('translates via LibreTranslate against the configured url', function () {
    config()->set('admin-core.translation.libretranslate.url', 'https://lt.example.test');
    Http::fake([
        'lt.example.test/translate' => Http::response(['translatedText' => 'សួស្ដី']),
    ]);

    expect((new LibreTranslateTranslator)->translate('Hello', 'en', 'km'))->toBe('សួស្ដី');
});

it('is fail-safe: a provider error returns the original text unchanged', function () {
    Http::fake(['api.mymemory.translated.net/*' => Http::response('boom', 500)]);

    expect((new MyMemoryTranslator)->translate('Hello', 'en', 'fr'))->toBe('Hello');
});

it('short-circuits empty text, same locale and over-length input without calling the provider', function () {
    Http::fake(); // any call would record; we assert none happen
    config()->set('admin-core.translation.max_length', 5);

    $t = new MyMemoryTranslator;
    expect($t->translate('', 'en', 'fr'))->toBe('')
        ->and($t->translate('Hello', 'en', 'en'))->toBe('Hello')
        ->and($t->translate('TooLong!', 'en', 'fr'))->toBe('TooLong!');

    Http::assertNothingSent();
});

it('the null driver returns the text unchanged', function () {
    expect((new NullTranslator)->translate('Hello', 'en', 'km'))->toBe('Hello');
});
