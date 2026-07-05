<?php

use Illuminate\Support\Facades\File;

/*
 * admin-core:doctor surfaces STUB DRIFT — published frontend files (e.g. resources/js/datepicker.js) that
 * have fallen behind the package version, so a later fix doesn't sit silently unapplied. Reports by default
 * (non-zero exit on drift); --fix restores them to the package version.
 */

function doctorStub(): string
{
    return dirname(__DIR__, 2) . '/stubs/frontend/resources/js/datepicker.js.stub';
}

function doctorDest(): string
{
    return resource_path('js/datepicker.js');
}

beforeEach(function () {
    // Establish the "frontend kit installed" signal (an admin-core-specific asset), so doctor actually
    // inspects files — on a minimal install it (correctly) skips, and would otherwise report nothing here.
    File::ensureDirectoryExists(resource_path('js'));
    File::copy(dirname(__DIR__, 2) . '/stubs/frontend/resources/js/datatable.js.stub', resource_path('js/datatable.js'));
});

afterEach(function () {
    File::deleteDirectory(resource_path('js'));
});

it('skips entirely when the frontend kit is not installed (minimal install — no false drift)', function () {
    File::deleteDirectory(resource_path('js'));   // undo the beforeEach signal → a minimal (config-only) install
    File::ensureDirectoryExists(dirname(doctorDest()));
    File::put(doctorDest(), "// a framework/CDN default, NOT ours to manage\n");

    $this->artisan('admin-core:doctor')
        ->expectsOutputToContain('frontend kit not installed')
        ->assertSuccessful(); // no false "drifted"/"missing", exit 0

    expect(File::get(doctorDest()))->toBe("// a framework/CDN default, NOT ours to manage\n"); // untouched
});

it('reports a file that matches the package as in sync (no drift for it)', function () {
    File::ensureDirectoryExists(dirname(doctorDest()));
    File::copy(doctorStub(), doctorDest()); // identical to the package version

    // The js folder now exists so siblings read as "missing", but datepicker.js itself is NOT in the
    // drifted list — assert it isn't reported as drifted.
    $this->artisan('admin-core:doctor')
        ->doesntExpectOutputToContain('resources/js/datepicker.js [behaviour]');
});

it('flags a modified published file as drifted and exits non-zero', function () {
    File::ensureDirectoryExists(dirname(doctorDest()));
    File::put(doctorDest(), File::get(doctorStub()) . "\n// a stale local copy missing a later fix\n");

    $this->artisan('admin-core:doctor')
        ->expectsOutputToContain('resources/js/datepicker.js')
        ->assertFailed(); // drift present → non-zero (CI-catchable)
});

it('refuses to --fix without --force in a non-interactive context (no accidental overwrite)', function () {
    File::ensureDirectoryExists(dirname(doctorDest()));
    File::put(doctorDest(), "// stale, must NOT be touched\n");

    // --no-interaction simulates CI (no TTY): without --force, refuse rather than silently overwrite.
    $this->artisan('admin-core:doctor', ['--fix' => true, '--no-interaction' => true])->assertFailed();

    expect(File::get(doctorDest()))->toBe("// stale, must NOT be touched\n"); // untouched
});

it('restores a drifted file to the current package version with --fix', function () {
    File::ensureDirectoryExists(dirname(doctorDest()));
    File::put(doctorDest(), "// totally stale\n");
    expect(File::get(doctorDest()))->not->toBe(File::get(doctorStub()));

    $this->artisan('admin-core:doctor', ['--fix' => true, '--force' => true])->assertSuccessful();

    expect(File::get(doctorDest()))->toBe(File::get(doctorStub())); // now matches the package
});

it('reports a WIPED subtree as missing (not a false "in sync") and exits non-zero', function () {
    // Install the views/backend area (its root survives), then delete the whole partials/ subtree — the
    // parent dir is gone too, which used to make those files fall through unreported → a false clean bill.
    $backendSrc = dirname(__DIR__, 2) . '/stubs/frontend/views/backend';
    $backendDest = resource_path('views/backend');
    File::copyDirectory($backendSrc, $backendDest);
    // The .stub suffix isn't stripped by copyDirectory, so mirror what doctor expects for the partial it flags.
    $sidebar = $backendDest . '/partials/sidebar.blade.php';
    File::ensureDirectoryExists(dirname($sidebar));
    File::copy($backendSrc . '/partials/sidebar.blade.php.stub', $sidebar);

    // Wipe the whole partials/ subtree (bad merge / git clean) — the directory itself is gone.
    File::deleteDirectory($backendDest . '/partials');
    expect(File::isDirectory($backendDest))->toBeTrue()                       // the AREA root survives…
        ->and(File::isDirectory($backendDest . '/partials'))->toBeFalse();    // …but the subtree is gone

    $this->artisan('admin-core:doctor')
        ->expectsOutputToContain('sidebar.blade.php') // the wiped partial is reported MISSING…
        ->assertFailed();                             // …and the exit code is non-zero (CI-catchable)

    File::deleteDirectory($backendDest);
});
