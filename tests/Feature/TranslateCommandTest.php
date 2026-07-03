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

it('does not freeze a failed provider call as a translation — the key retries on the next run', function () {
    // One fake whose behaviour flips between the two runs (repeated Http::fake() calls don't replace).
    $failing = true;
    Http::fake(function () use (&$failing) { // by-reference so the two runs see the flip
        // HTTP-200 but responseStatus 429 (quota) → the translator throws → fails safe to the SOURCE string.
        return $failing
            ? Http::response(['responseStatus' => 429, 'responseData' => ['translatedText' => 'quota']])
            : Http::response(['responseData' => ['translatedText' => 'DONE']]);
    });

    // First run: every call fails. The command must NOT persist the source as a "translation".
    $this->artisan('admin-core:translate', ['locale' => 'th'])->assertSuccessful();
    $first = File::exists($this->target) ? File::get($this->target) : '';
    expect($first)->not->toContain("'language' =>"); // absent (not frozen) → runtime falls back + next run retries

    // Second run (no --force): the provider now succeeds → the key that failed before is translated,
    // proving the earlier failure was retryable, not frozen into the file.
    $failing = false;
    $this->artisan('admin-core:translate', ['locale' => 'th'])->assertSuccessful();
    expect(File::get($this->target))->toContain("'language' => 'DONE'");
});

it('refreshes a placeholder string to the current source on --force (not frozen to a stale copy)', function () {
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response(['responseData' => ['translatedText' => 'XX']]),
    ]);
    File::ensureDirectoryExists(dirname($this->target));
    // A stale verbatim-English copy of a placeholder string from an OLD source wording.
    File::put($this->target, "<?php\n\nreturn ['auth' => ['two_factor_throttled' => 'OLD wording :seconds']];\n");

    // Without --force: the existing (possibly hand-translated) value is kept — placeholders never machine-translate.
    $this->artisan('admin-core:translate', ['locale' => 'th'])->assertSuccessful();
    expect(File::get($this->target))->toContain('OLD wording :seconds');

    // With --force: it re-seeds the CURRENT English source (still verbatim — never machine-mangled), so the
    // stale wording is refreshed for the human to re-translate, not frozen forever.
    $this->artisan('admin-core:translate', ['locale' => 'th', '--force' => true])->assertSuccessful();
    expect(File::get($this->target))
        ->not->toContain('OLD wording')
        ->toContain(':seconds'); // the current source (with its intact placeholder) was re-seeded
});

it('persists a genuine identity translation and does not re-fetch it every run (converges)', function () {
    // A provider that legitimately returns some strings unchanged (identity, e.g. 'OK'/'URL' on a language
    // pair where they're the same). This is a SUCCESS, not a failure — it must be persisted so the command
    // converges, not dropped and re-fetched forever (the quota-burn bug from the blunt v2.79.59 check).
    $calls = 0;
    Http::fake(function (\Illuminate\Http\Client\Request $req) use (&$calls) {
        $calls++;
        parse_str(parse_url($req->url(), PHP_URL_QUERY) ?: '', $q);

        return Http::response(['responseData' => ['translatedText' => $q['q'] ?? '']]); // echo the source back
    });

    $this->artisan('admin-core:translate', ['locale' => 'th'])->assertSuccessful();
    $afterFirst = $calls;
    expect($afterFirst)->toBeGreaterThan(0)
        ->and(File::get($this->target))->toContain("'language' => 'Language'"); // the identity result IS written

    // Second run (no --force): the persisted identity keys are KEPT, so NO new provider calls for them.
    $this->artisan('admin-core:translate', ['locale' => 'th'])->assertSuccessful();
    expect($calls)->toBe($afterFirst); // converged — zero re-fetches
});

it('fails clearly when the driver is null', function () {
    config()->set('admin-core.translation.driver', 'null');

    $this->artisan('admin-core:translate', ['locale' => 'th'])->assertFailed();
});

it('never machine-translates a string carrying a :placeholder (keeps it intact)', function () {
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response(['responseData' => ['translatedText' => 'XX']]),
    ]);

    $this->artisan('admin-core:translate', ['locale' => 'th'])->assertSuccessful();

    // auth.two_factor_throttled carries ":seconds" — it must survive verbatim (not become 'XX'),
    // or runtime substitution would break.
    expect(File::get($this->target))->toContain(':seconds');
});
