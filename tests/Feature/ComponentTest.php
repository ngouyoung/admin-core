<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;

// Components read $errors for the is-invalid state; share an empty bag (no request lifecycle here).
beforeEach(fn () => view()->share('errors', new ViewErrorBag));

it('renders the input component as a labelled, classed control', function () {
    $html = Blade::render('<x-admin-core::input name="price" type="number" :value="9" step="0.01" />');
    expect($html)
        ->toContain('name="price"')
        ->toContain('type="number"')
        ->toContain('class="form-control"')
        ->toContain('value="9"')
        ->toContain('step="0.01"')      // extra attribute passes through
        ->toContain('Price:');          // label from the headline of the name
});

it('renders the textarea component with its value', function () {
    $html = Blade::render('<x-admin-core::textarea name="note" value="hello" rows="5" />');
    expect($html)->toContain('<textarea')->toContain('name="note"')->toContain('rows="5"')->toContain('hello');
});

it('renders the select component with options, a placeholder and the selected value', function () {
    $html = Blade::render(
        '<x-admin-core::select name="status" :options="$o" value="active" placeholder="— pick —" />',
        ['o' => ['active' => 'Active', 'inactive' => 'Inactive']],
    );
    expect($html)
        ->toContain('name="status"')
        ->toContain('admin-core-select')          // select2-enhanced by default
        ->toContain('<option value="">— pick —</option>')
        ->toContain('Active')
        ->toContain('selected');                  // the active option is marked selected
});

it('renders the button as a <button>, and as an <a> when href is given', function () {
    expect(Blade::render('<x-admin-core::button variant="danger" icon="bi bi-trash">Delete</x-admin-core::button>'))
        ->toContain('<button')->toContain('btn btn-danger')->toContain('bi bi-trash')->toContain('Delete');
    expect(Blade::render('<x-admin-core::button :href="\'/back\'" outline>Back</x-admin-core::button>'))
        ->toContain('<a href="/back"')->toContain('btn btn-outline-primary')->toContain('Back');
});
