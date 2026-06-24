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

afterEach(function () {
    File::deleteDirectory(resource_path('js'));
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
