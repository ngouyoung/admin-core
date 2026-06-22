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

it('renders an alert with the right variant, icon and optional dismiss', function () {
    $html = Blade::render('<x-admin-core::alert type="warning" dismissible>Careful</x-admin-core::alert>');
    expect($html)
        ->toContain('alert alert-warning')
        ->toContain('alert-dismissible')
        ->toContain('bi-exclamation-triangle')
        ->toContain('Careful')
        ->toContain('data-bs-dismiss="alert"');

    // error maps to danger; no dismiss button unless asked.
    expect(Blade::render('<x-admin-core::alert type="error">Boom</x-admin-core::alert>'))
        ->toContain('alert-danger')->not->toContain('data-bs-dismiss="alert"');
});

it('renders a modal shell with title, body and footer slots', function () {
    $html = Blade::render(<<<'BLADE'
        <x-admin-core::modal id="editX" title="Edit item" size="lg">
            Body here
            <x-slot:footer><button class="btn btn-primary">Save</button></x-slot:footer>
        </x-admin-core::modal>
        BLADE);

    expect($html)
        ->toContain('id="editX"')
        ->toContain('modal-dialog modal-lg')
        ->toContain('<h5 class="modal-title">Edit item</h5>')
        ->toContain('Body here')
        ->toContain('<div class="modal-footer">');
});

it('renders an empty-state with icon, title, message and action slot', function () {
    $html = Blade::render(<<<'BLADE'
        <x-admin-core::empty-state icon="bi-inbox" title="No products" message="Add one to start.">
            <x-slot:action><a href="/x" class="btn btn-primary">Add</a></x-slot:action>
        </x-admin-core::empty-state>
        BLADE);

    expect($html)
        ->toContain('class="ac-empty"')
        ->toContain('bi bi-inbox ac-empty-icon')
        ->toContain('No products')
        ->toContain('Add one to start.')
        ->toContain('ac-empty-action');
});

it('renders skeleton placeholders for text, card and table types', function () {
    expect(Blade::render('<x-admin-core::skeleton :lines="3" />'))
        ->toContain('ac-skeleton-text')
        ->and(substr_count(Blade::render('<x-admin-core::skeleton :lines="3" />'), 'ac-skeleton-line'))->toBe(3);

    expect(Blade::render('<x-admin-core::skeleton type="card" :lines="2" />'))
        ->toContain('card')->toContain('ac-skeleton-title');

    $table = Blade::render('<x-admin-core::skeleton type="table" :rows="4" :cols="3" />');
    expect(substr_count($table, '<tr>'))->toBe(4)
        ->and(substr_count($table, 'ac-skeleton-line'))->toBe(12); // 4 rows × 3 cols
});

it('renders content tabs with the first nav item active and the panes', function () {
    $html = Blade::render(<<<'BLADE'
        <x-admin-core::tabs :tabs="['profile' => 'Profile', 'security' => 'Security']">
            <x-admin-core::tab-pane id="profile" active>Profile body</x-admin-core::tab-pane>
            <x-admin-core::tab-pane id="security">Security body</x-admin-core::tab-pane>
        </x-admin-core::tabs>
        BLADE);

    expect($html)
        ->toContain('nav nav-tabs')
        ->toContain('data-bs-target="#profile"')
        ->toContain('data-bs-target="#security"')
        ->toContain('class="nav-link active"')           // first tab active
        ->toContain('id="profile"')->toContain('Profile body')
        ->toContain('tab-pane fade show active')          // first pane active
        ->toContain('Security body');
});

it('renders an avatar as an image when src is set, else an initials circle', function () {
    expect(Blade::render('<x-admin-core::avatar src="/me.jpg" name="Jane Doe" size="48" />'))
        ->toContain('<img')->toContain('src="/me.jpg"')->toContain('width: 48px');

    // No src → coloured circle with up to two initials.
    expect(Blade::render('<x-admin-core::avatar name="Jane Doe" />'))
        ->toContain('ac-avatar-initials')
        ->toContain('>JD<')
        ->toContain('background: hsl');
});

it('renders a badge with a tone and optional pill', function () {
    expect(Blade::render('<x-admin-core::badge tone="danger" pill>3</x-admin-core::badge>'))
        ->toContain('badge text-bg-danger')
        ->toContain('rounded-pill')
        ->toContain('>3</span>');
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

it('renders a detail-list with detail-row label/value pairs (show pages)', function () {
    $html = Blade::render(
        '<x-admin-core::detail-list>'
        . '<x-admin-core::detail-row label="Email">a@b.com</x-admin-core::detail-row>'
        . '<x-admin-core::detail-row label="Type" width="140px"><code>X</code></x-admin-core::detail-row>'
        . '</x-admin-core::detail-list>'
    );

    expect($html)
        ->toContain('table table-bordered')
        ->toContain('Email')
        ->toContain('<td>a@b.com</td>')
        ->toContain('width: 140px')        // per-row width prop
        ->toContain('<td><code>X</code></td>');
});

it('renders a modal with id/title/centered, body slot, and footer (button ids pass through)', function () {
    $html = Blade::render(
        '<x-admin-core::modal id="cropModal" title="Crop" centered>'
        . '<div id="croppie-area"></div>'
        . '<x-slot:footer><x-admin-core::button id="crop-save" variant="primary">Save</x-admin-core::button></x-slot:footer>'
        . '</x-admin-core::modal>'
    );

    expect($html)
        ->toContain('id="cropModal"')
        ->toContain('modal-dialog-centered')   // centered prop
        ->toContain('Crop')                    // title
        ->toContain('id="croppie-area"')       // body slot
        ->toContain('modal-footer')
        ->toContain('id="crop-save"');         // button id passthrough — the croppie JS depends on it
});
