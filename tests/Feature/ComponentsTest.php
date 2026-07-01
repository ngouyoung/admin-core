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

it('renders transition buttons — a plain action posts, an input action opens a modal with its fields', function () {
    $items = [
        ['key' => 'post', 'label' => 'Post', 'icon' => 'bi bi-send', 'color' => 'success',
            'url' => '/admin/x/transition/1/post', 'confirm' => null, 'form' => null],
        ['key' => 'close', 'label' => 'Close', 'icon' => null, 'color' => 'primary',
            'url' => '/admin/x/transition/1/close', 'confirm' => null, 'form' => [
                ['name' => 'counted', 'label' => 'Counted', 'type' => 'number', 'options' => [], 'required' => true],
                ['name' => 'method', 'label' => 'Method', 'type' => 'select', 'options' => ['cash' => 'Cash'], 'required' => false],
                ['name' => 'note', 'label' => 'Note', 'type' => 'textarea', 'options' => [], 'required' => false],
            ]],
    ];
    $html = Blade::render('<x-admin-core::transitions :items="$items" />', ['items' => $items]);

    // Plain action: a POST form carrying the idempotency token.
    expect($html)->toContain('action="/admin/x/transition/1/post"')
        ->toContain('name="_idempotency_key"');

    // Input action: a button that opens the modal + the modal with one control per field.
    expect($html)->toContain('data-bs-target="#ac-tr-close"')
        ->toContain('id="ac-tr-close"')                       // the modal
        ->toContain('type="number"')->toContain('name="counted"')
        ->toContain('<select name="method"')->toContain('>Cash<')
        ->toContain('<textarea name="note"')
        ->toContain('action="/admin/x/transition/1/close"');  // modal form posts to the transition
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

it('emits a data-ac-datatable config (columns + ajax + bulk + i18n) when :columns is given', function () {
    View::addNamespace('actmp', sys_get_temp_dir());
    $thead = sys_get_temp_dir() . '/ac_thead.blade.php';
    File::put($thead, '<tr><th>Name</th></tr>');

    $html = Blade::render(
        '<x-admin-core::data-table id="t_table" thead="actmp::ac_thead" :ajax="$ajax" :bulk-delete="$bulk" :columns="$columns" />',
        [
            'ajax' => '/admin/widgets/getData',
            'bulk' => '/admin/widgets/bulkDelete',
            'columns' => [
                ['type' => 'check', 'data' => 'uuid'],
                ['data' => 'name', 'name' => 'name'],
                ['data' => 'actions', 'orderable' => false, 'searchable' => false],
            ],
        ]
    );

    expect($html)->toContain('data-ac-datatable=');
    preg_match('/data-ac-datatable="([^"]*)"/', $html, $m);
    $cfg = json_decode(html_entity_decode($m[1], ENT_QUOTES), true); // attribute is HTML-escaped JSON

    expect($cfg['ajax'])->toBe('/admin/widgets/getData')
        ->and($cfg['bulk']['url'])->toBe('/admin/widgets/bulkDelete')
        ->and($cfg['columns'][0]['type'])->toBe('check')   // the only client-rendered type
        ->and($cfg['columns'][1]['data'])->toBe('name')
        ->and($cfg['i18n'])->toHaveKey('deleted')          // locale-aware strings ship in the config
        ->and($cfg['i18n'])->toHaveKey('confirmDelete');

    File::delete($thead);
});

it('omits the data-ac-datatable attribute without :columns (backward-compatible)', function () {
    View::addNamespace('actmp', sys_get_temp_dir());
    $thead = sys_get_temp_dir() . '/ac_thead.blade.php';
    File::put($thead, '<tr><th>Name</th></tr>');

    $html = Blade::render('<x-admin-core::data-table id="t_table" thead="actmp::ac_thead" />');

    expect($html)->not->toContain('data-ac-datatable'); // existing per-resource scripts still drive it

    File::delete($thead);
});

it('emits the custom bulk actions into the datatable config', function () {
    View::addNamespace('actmp', sys_get_temp_dir());
    $thead = sys_get_temp_dir() . '/ac_thead.blade.php';
    File::put($thead, '<tr><th>Name</th></tr>');

    $html = Blade::render(
        '<x-admin-core::data-table id="t_table" thead="actmp::ac_thead" :columns="$columns" :actions="$actions" />',
        [
            'columns' => [['type' => 'check', 'data' => 'id'], ['data' => 'name', 'name' => 'name']],
            'actions' => [
                ['key' => 'publish', 'label' => 'Publish', 'icon' => 'bi bi-send', 'color' => 'success', 'url' => '/admin/x/action/publish', 'confirm' => 'Sure?'],
            ],
        ]
    );

    preg_match('/data-ac-datatable="([^"]*)"/', $html, $m);
    $cfg = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

    expect($cfg['actions'][0])->toMatchArray([
        'key' => 'publish',
        'label' => 'Publish',
        'url' => '/admin/x/action/publish',
        'confirm' => 'Sure?',
    ]);

    File::delete($thead);
});

it('renders a toolbar header for bulk actions even without a toolbar slot (so JS has somewhere to inject)', function () {
    View::addNamespace('actmp', sys_get_temp_dir());
    $thead = sys_get_temp_dir() . '/ac_thead.blade.php';
    File::put($thead, '<tr><th>Name</th></tr>');

    $html = Blade::render(
        '<x-admin-core::data-table id="t_table" thead="actmp::ac_thead" :columns="$columns" :actions="$actions" />',
        [
            'columns' => [['data' => 'name', 'name' => 'name']],
            'actions' => [['key' => 'go', 'label' => 'Go', 'icon' => null, 'color' => 'primary', 'url' => '/x/action/go', 'confirm' => null]],
        ]
    );

    expect($html)->toContain('card-header'); // header present though no <x-slot:toolbar> was passed

    File::delete($thead);
});

it('field-guard passes a field through normally, but locks it in a disabled fieldset when denied', function () {
    // Not in the deny list → plain passthrough, no fieldset.
    $open = Blade::render(
        '<x-admin-core::field-guard name="name" :denied-fields="[]"><input name="name"></x-admin-core::field-guard>'
    );
    expect($open)->toContain('<input name="name"')->not->toContain('<fieldset');

    // In the deny list → wrapped in a disabled <fieldset> (which excludes its controls from the submit).
    $locked = Blade::render(
        '<x-admin-core::field-guard name="secret" :denied-fields="[\'secret\']"><input name="secret"></x-admin-core::field-guard>'
    );
    expect($locked)->toContain('<fieldset disabled')->toContain('<input name="secret"');
});

it('resolves a remote select from :source via the route prefix (searchable + paginated)', function () {
    // admin.widgets.select is registered by the test harness's Route::crud('widget', …).
    $html = Blade::render('<x-admin-core::select name="widget_id" source="widgets" placeholder="— search —" />');

    expect($html)
        ->toContain('admin-core-select-ajax')                 // ajax mode (not the static class)
        ->toContain('data-ajax-url="http://localhost/admin/widgets/select"'); // built from the config prefix
});

it('falls back to a static select when :source has no select route (prefix-safe, never errors)', function () {
    $html = Blade::render('<x-admin-core::select name="x_id" source="nopesnothere" :options="[1 => \'One\']" placeholder="— pick —" />');

    expect($html)
        ->not->toContain('admin-core-select-ajax')
        ->not->toContain('data-ajax-url')
        ->toContain('admin-core-select')   // plain static select
        ->toContain('>One</option>');      // renders the given options
});

it('emits data-ac-depends for a cascading (dependent) remote select', function () {
    $html = Blade::render('<x-admin-core::select name="commune_id" source="widgets" :depends-on="[\'province_id\' => \'#province_id\']" placeholder="—" />');

    expect($html)
        ->toContain('admin-core-select-ajax')
        ->toContain('data-ac-depends=')   // the cascade map the JS reads
        ->toContain('province_id')
        ->toContain('#province_id');
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

it('renders a checkbox with the hidden-0 before the box (unchecked still submits)', function () {
    $html = Blade::render('<x-admin-core::checkbox name="active" label="Active" :checked="true" />');

    expect($html)
        ->toContain('<input type="hidden" name="active" value="0">')
        ->toContain('type="checkbox"')
        ->toContain('form-check')
        ->toContain('checked');
    expect(strpos($html, 'value="0"'))->toBeLessThan(strpos($html, 'type="checkbox"'));
});

it('renders a file-input with image/file variants + current-value preview', function () {
    $img = Blade::render('<x-admin-core::file-input name="avatar" image :value="\'https://x/a.jpg\'" />');
    expect($img)->toContain('type="file"')->toContain('accept="image/*"')->toContain('src="https://x/a.jpg"');

    $file = Blade::render('<x-admin-core::file-input name="doc" :value="\'https://x/d.pdf\'" />');
    expect($file)->toContain('type="file"')->not->toContain('accept="image/*"')
        ->toContain('Current:')->toContain('href="https://x/d.pdf"'); // localized current-file link

    expect(Blade::render('<x-admin-core::file-input name="x" />'))->not->toContain('<img')->not->toContain('Current:');
});

it('renders an input hint as muted form-text (and omits it when absent)', function () {
    $h = Blade::render('<x-admin-core::input name="password" type="password" hint="Leave blank to keep" required />');
    expect($h)->toContain('type="password"')->toContain('required')->toContain('form-text')->toContain('Leave blank to keep');
    expect(Blade::render('<x-admin-core::input name="x" />'))->not->toContain('form-text');
});

it('renders a translatable-input with one field per configured locale (+ the AutoTranslate marker)', function () {
    config(['admin-core.translation.locales' => ['en' => 'English', 'km' => 'Khmer']]);

    $html = Blade::render('<x-admin-core::translatable-input name="title" :value="[\'en\' => \'Hi\']" />');

    expect($html)
        ->toContain('data-ac-translatable="title"')   // the JS/AutoTranslate hook
        ->toContain('name="title[en]"')               // one input per locale
        ->toContain('name="title[km]"')
        ->toContain('Hi');                            // existing en value rendered
});

it('shows a validation error for a bracket-named field (settings[logo] -> settings.logo)', function () {
    // Laravel keys errors with dot notation; the components must normalise the bracket name to find it.
    $bag = (new ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['settings.logo' => 'The logo must be an image.']));
    View::share('errors', $bag);

    $html = Blade::render('<x-admin-core::file-input name="settings[logo]" image />');

    expect($html)->toContain('is-invalid')->toContain('The logo must be an image.');
});

it('renders a repeater: existing rows server-side, a clone template, and an add button', function () {
    // A throwaway row view for the repeater's @include (same fixture trick as the data-table test).
    View::addNamespace('actmp', sys_get_temp_dir());
    $rowView = sys_get_temp_dir() . '/ac_repeater_row.blade.php';
    File::put($rowView, '<div data-ac-repeater-row><input name="{{ $name }}[{{ $index }}][unit_id]" value="{{ $row[\'unit_id\'] ?? \'\' }}"><button type="button" data-ac-repeater-remove>x</button></div>');

    $html = Blade::render(
        '<x-admin-core::repeater name="units" :rows="$rows" row="actmp::ac_repeater_row" add-label="Add unit" />',
        ['rows' => [['unit_id' => 7], ['unit_id' => 9]]]
    );

    expect($html)
        ->toContain('data-ac-repeater')
        ->toContain('name="units[0][unit_id]"')->toContain('value="7"') // existing row 0, server-rendered
        ->toContain('name="units[1][unit_id]"')->toContain('value="9"') // existing row 1
        ->toContain('name="units[__ROW__][unit_id]"')                   // clone template — JS swaps __ROW__
        ->toContain('data-ac-repeater-add')->toContain('Add unit')
        ->toContain('data-ac-repeater-remove');

    // The template lives inside a <template> so its inputs don't post until cloned.
    expect($html)->toMatch('/<template[^>]*data-ac-repeater-tpl/');

    File::delete($rowView);
});

it('renders a select with a placeholder select2 can read (data-placeholder) + the selected option', function () {
    $html = Blade::render('<x-admin-core::select name="category_id" :options="[\'1\' => \'Phones\', \'2\' => \'Audio\']" :value="\'2\'" placeholder="— choose —" />');

    expect($html)
        ->toContain('name="category_id"')
        ->toContain('admin-core-select')                      // select2-enhanced
        ->toContain('data-placeholder="— choose —"')          // select2 reads this (multi-selects too)
        ->toContain('<option value="">— choose —</option>')   // leading empty option for the single select
        ->toContain('value="2" selected');                    // current value preselected
});

it('renders the sidebar menu with ARIA: aria-current on the active link, aria-expanded + aria-controls on a collapsible group', function () {
    $items = [
        ['label' => 'Dashboard', 'url' => '/admin', 'match' => '*'],            // active: request()->is('*') is true
        ['label' => 'Catalog', 'icon' => 'bi bi-folder', 'match' => 'nope/*', 'children' => [
            ['label' => 'Products', 'url' => '/admin/products', 'match' => 'admin/products*'],
        ]],
    ];
    $html = Blade::render('<x-admin-core::sidebar-menu :items="$items" />', ['items' => $items]);

    expect($html)
        ->toContain('aria-current="page"')           // the active leaf announces it
        ->toContain('role="button"')                 // the collapsible toggle is a button, not a link to nowhere
        ->toContain('aria-expanded="false"')         // closed group (its match didn't hit)
        ->toContain('aria-hidden="true"');           // decorative icons are hidden from SR

    // The toggle's aria-controls must reference an id that actually exists on its treeview (unique per render).
    expect($html)->toMatch('/aria-controls="(ac-tv-\w+)"/');
    preg_match('/aria-controls="(ac-tv-\w+)"/', $html, $m);
    expect($html)->toContain('id="' . $m[1] . '"');
});

it('renders a date-input wired to the datepicker, formatting a Carbon value', function () {
    $html = Blade::render('<x-admin-core::date-input name="received_date" :value="$v" />', ['v' => \Illuminate\Support\Carbon::parse('2026-06-24 13:45')]);
    expect($html)
        ->toContain('name="received_date"')
        ->toContain('data-adp="date"')
        ->toContain('autocomplete="off"')
        ->toContain('value="2026-06-24"')                                  // date mode → Y-m-d
        ->toMatch('/class="[^"]*\bform-control\b[^"]*\bjs-datepicker\b/');  // both classes on the SAME input
});

it('forwards pass-through attributes (readonly/required) onto the input and wires field errors', function () {
    // ->class() preserves the rest of the bag, so extra attributes reach the <input>.
    $html = Blade::render('<x-admin-core::date-input name="d" readonly required placeholder="Pick" />');
    expect($html)->toContain('readonly')->toContain('required')->toContain('placeholder="Pick"');

    // A non-stringifiable value never throws — it just renders empty.
    expect(Blade::render('<x-admin-core::date-input name="d" :value="$v" />', ['v' => new stdClass]))->toContain('value=""');

    // is-invalid is wired by field name through the composed input component.
    View::share('errors', (new ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['born_on' => 'Bad date.'])));
    expect(Blade::render('<x-admin-core::date-input name="born_on" />'))->toContain('is-invalid');
    View::share('errors', new ViewErrorBag); // reset for later tests
});

it('renders a datetime date-input and echoes a plain string value verbatim (no parse, no crash)', function () {
    $dt = Blade::render('<x-admin-core::date-input name="starts_at" mode="datetime" :value="$v" />', ['v' => \Illuminate\Support\Carbon::parse('2026-06-24 13:45')]);
    expect($dt)->toContain('data-adp="datetime"')->toContain('value="2026-06-24 13:45"'); // datetime → Y-m-d H:i

    // A re-submitted raw string (e.g. after a validation failure) is output as-is — never re-parsed.
    expect(Blade::render('<x-admin-core::date-input name="d" :value="$v" />', ['v' => 'not-a-date']))
        ->toContain('value="not-a-date"');
});

it('renders the global search as an accessible combobox + listbox', function () {
    config(['admin-core.search' => [['model' => \Ngos\AdminCore\Tests\Fixtures\Widget::class, 'columns' => ['name']]]]);
    \Illuminate\Support\Facades\Route::get('/_search', fn () => '')->name('admin.search');
    \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups(); // make Route::has see the runtime route

    $html = Blade::render('<x-admin-core::global-search />');

    expect($html)
        ->toContain('role="search"')                       // landmark
        ->toContain('role="combobox"')                     // the input
        ->toContain('aria-expanded="false"')               // closed until results
        ->toContain('aria-controls="ac-gsearch-results"')  // input → results
        ->toContain('aria-autocomplete="list"')
        ->toContain('role="listbox"')                      // the results container
        ->toContain('aria-live="polite"')                  // SR result-count announcer
        ->toContain('visually-hidden');                    // the label + status are SR-only
});
