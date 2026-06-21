<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
 * `admin-core:translate <locale>` — machine-translates the English UI strings into a new locale file.
 */

beforeEach(function () {
    config()->set('admin-core.translation.enabled', true);
    config()->set('admin-core.translation.driver', 'mymemory');
    $this->target = lang_path('vendor/admin-core/th/admin-core.php');
    File::delete($this->target);
});

afterEach(function () {
    File::delete($this->target);
});

it('generates a translated language file (nested keys included)', function () {
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response(['responseData' => ['translatedText' => 'XX']]),
    ]);

    $this->artisan('admin-core:translate', ['locale' => 'th'])->assertSuccessful();

    expect(File::exists($this->target))->toBeTrue();
    expect(File::get($this->target))
        ->toContain("'save' => 'XX'")   // a nested actions.* key was translated
        ->toContain("'language' => 'XX'");
});

it('keeps an existing translation unless --force is passed', function () {
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response(['responseData' => ['translatedText' => 'NEW']]),
    ]);
    File::ensureDirectoryExists(dirname($this->target));
    File::put($this->target, "<?php\n\nreturn ['language' => 'KEEP'];\n");

    // Without --force: the existing value is preserved.
    $this->artisan('admin-core:translate', ['locale' => 'th'])->assertSuccessful();
    expect(File::get($this->target))->toContain("'language' => 'KEEP'");

    // With --force: it is re-translated.
    $this->artisan('admin-core:translate', ['locale' => 'th', '--force' => true])->assertSuccessful();
    expect(File::get($this->target))->toContain("'language' => 'NEW'");
});

it('fails clearly when the driver is null', function () {
    config()->set('admin-core.translation.driver', 'null');

    $this->artisan('admin-core:translate', ['locale' => 'th'])->assertFailed();
});
