<?php

use Illuminate\Support\Facades\File;

/*
 * The in-app Documentation page shipped by --access: a sidebar entry (config menu), an
 * ungated view route (account.php), and a Blade guide that composes the components.
 */

function docsStub(string $rel): string
{
    return File::get(__DIR__ . '/../../stubs/' . $rel);
}

it('lists a Documentation entry in the default sidebar menu', function () {
    $entry = collect(config('admin-core.menu'))->firstWhere('route', 'admin.docs');

    expect($entry)->not->toBeNull()
        ->and($entry['label'])->toBe('Documentation')
        ->and($entry)->not->toHaveKey('can'); // help is visible to every admin
});

it('wires an ungated docs view route in the access account routes', function () {
    expect(docsStub('access/routes/account.php.stub'))
        ->toContain("Route::view('docs', 'backend.docs.index')->name('docs')");
});

it('ships a docs view that composes the components', function () {
    expect(docsStub('access/views/backend/docs/index.blade.php.stub'))
        ->toContain("@extends('backend.layouts.app')")
        ->toContain('<x-admin-core::page-header')
        ->toContain('<x-admin-core::tabs')
        ->toContain('<x-admin-core::tab-pane')
        ->toContain('<x-admin-core::card>')
        ->toContain('<x-admin-core::alert')
        ->toContain('admin-core:make'); // the guide actually documents the commands
});

it('never echoes a Blade-reserved variable as loop/data', function () {
    // Inside a component's render scope $component/$attributes/$slot are bound to the
    // component machinery — reusing them as a foreach variable echoes an object and
    // blows up htmlspecialchars(). Guard the whole stub against that class of bug.
    $stub = docsStub('access/views/backend/docs/index.blade.php.stub');

    foreach (['component', 'attributes', 'slot', 'errors'] as $reserved) {
        expect($stub)->not->toContain('as $' . $reserved . ')');
    }
});
