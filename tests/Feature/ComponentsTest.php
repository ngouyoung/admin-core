<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

/*
 * The reusable x-admin-core::* UI elements the generator emits. Rendering each one here
 * catches a broken component (bad prop, Blade syntax, missing slot) in CI — not only when
 * someone scaffolds a resource and loads the page.
 */

// @error needs the shared $errors bag the web middleware provides on a real request;
// Blade::render in isolation has none, so share an empty one.
beforeEach(fn () => View::share('errors', new ViewErrorBag));

it('renders a status pill from a value, and nothing when blank', function () {
    expect(Blade::render('<x-admin-core::status :value="$v" />', ['v' => 'in_progress']))
        ->toContain('class="ac-status"')
        ->toContain('data-status="in_progress"')
        ->toContain('In Progress'); // headline-cased label

    expect(trim(Blade::render('<x-admin-core::status :value="$v" />', ['v' => null])))->toBe('');
});

it('renders a form-row with label, the control slot, and the field error wired', function () {
    $html = Blade::render('<x-admin-core::form-row name="price" label="Price"><input id="price"></x-admin-core::form-row>');

    expect($html)
        ->toContain('for="price"')
        ->toContain('Price:')
        ->toContain('<input id="price">');
});

it('renders the export menu with one checkbox per field', function () {
    $html = Blade::render('<x-admin-core::export-menu route="/x/export" :fields="$f" />', [
        'f' => ['id' => 'ID', 'name' => 'Name'],
    ]);

    expect($html)
        ->toContain('action="/x/export"')
        ->toContain('value="id"')->toContain('>ID<')
        ->toContain('value="name"')->toContain('>Name<');
});

it('renders the import modal button + a form posting to the route', function () {
    $html = Blade::render('<x-admin-core::import-modal route="/x/import" template="/x/tmpl" title="Widgets" />');

    expect($html)
        ->toContain('data-bs-target="#importModal"')
        ->toContain('action="/x/import"')
        ->toContain('Import Widgets')
        ->toContain('href="/x/tmpl"'); // template link present when passed
});

it('renders a stat-card as a link with value, label and icon', function () {
    $html = Blade::render('<x-admin-core::stat-card label="Users" :count="42" icon="bi-people" route="/admin/users" tone="2" />');

    expect($html)
        ->toContain('href="/admin/users"')
        ->toContain('ac-stat ac-stat-2')
        ->toContain('>42<')
        ->toContain('>Users<')
        ->toContain('bi bi-people ac-stat-icon');

    // Without a route it renders the card but no anchor.
    expect(Blade::render('<x-admin-core::stat-card label="X" :count="1" />'))
        ->toContain('ac-stat')->not->toContain('<a ');
});

it('renders a card with header, body and footer slots', function () {
    $html = Blade::render(<<<'BLADE'
        <x-admin-core::card class="h-100">
            <x-slot:header>Overview</x-slot>
            Body
            <x-slot:footer>Foot</x-slot>
        </x-admin-core::card>
        BLADE);

    expect($html)
        ->toContain('class="card h-100"')          // attributes merge onto the card
        ->toContain('<div class="card-header">Overview</div>')
        ->toContain('card-body')->toContain('Body') // body wrapped
        ->toContain('<div class="card-footer">Foot</div>');

    // :body-class="''" drops the body wrapper (flush content).
    expect(Blade::render('<x-admin-core::card :body-class="\'\'">Flush</x-admin-core::card>'))
        ->toContain('Flush')->not->toContain('card-body');
});

it('renders form-actions with a submit button and a cancel link', function () {
    $html = Blade::render('<x-admin-core::form-actions submit="Create" cancel="/admin/x" />');

    expect($html)
        ->toContain('type="submit"')
        ->toContain('>Create</button>')
        ->toContain('href="/admin/x"')
        ->toContain('>Cancel</a>');

    // Submit-only when no cancel URL is given.
    expect(Blade::render('<x-admin-core::form-actions submit="Save" />'))
        ->toContain('>Save</button>')->not->toContain('>Cancel</a>');
});

it('renders the data-table shell with a toolbar slot and the thead included', function () {
    // A throwaway thead view for the component's @include.
    View::addNamespace('actmp', sys_get_temp_dir());
    $thead = sys_get_temp_dir() . '/ac_thead.blade.php';
    File::put($thead, '<tr><th>Name</th></tr>');

    $html = Blade::render(
        '<x-admin-core::data-table id="t_table" thead="actmp::ac_thead">'
        . '<x-slot:toolbar><button id="bulk-delete"></button></x-slot:toolbar>'
        . '</x-admin-core::data-table>'
    );

    expect($html)
        ->toContain('id="t_table"')
        ->toContain('<th>Name</th>')   // thead view was included
        ->toContain('id="bulk-delete"'); // toolbar slot rendered

    File::delete($thead);
});
