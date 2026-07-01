<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\Widget;

/* SingletonController: a one-record screen — update() saves the single row (creating it the first time),
   never a second. The route is PUT /admin/settings (no id). */

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('status')->nullable();
        $t->timestamps();
    });
});

it('creates the single record on the first save', function () {
    expect(Widget::count())->toBe(0);

    $this->put('/admin/settings', ['name' => 'Acme Ltd'])->assertRedirect();

    expect(Widget::count())->toBe(1)
        ->and(Widget::first()->name)->toBe('Acme Ltd');
});

it('updates the SAME record on later saves — never a second row', function () {
    $this->put('/admin/settings', ['name' => 'First'])->assertRedirect();
    $this->put('/admin/settings', ['name' => 'Updated'])->assertRedirect();

    expect(Widget::count())->toBe(1)               // still one row
        ->and(Widget::first()->name)->toBe('Updated');
});

it('validates the input like any form (name required) and saves nothing on failure', function () {
    $this->put('/admin/settings', ['name' => ''])->assertSessionHasErrors('name');

    expect(Widget::count())->toBe(0);
});

it('reflects an externally-created row (edits the existing one, not a new one)', function () {
    Widget::create(['name' => 'Seeded']);

    $this->put('/admin/settings', ['name' => 'Edited'])->assertRedirect();

    expect(Widget::count())->toBe(1)
        ->and(Widget::first()->name)->toBe('Edited');
});

it('re-asserts recordScope() after fill — a posted scope column cannot hijack the row (per-owner safety)', function () {
    // recordScope() = ['status' => 'locked']; a tampered `status` in the body must be forced back.
    $this->put('/admin/scoped-setting', ['name' => 'X', 'status' => 'tampered'])->assertRedirect();

    expect(Widget::count())->toBe(1)
        ->and(Widget::first()->name)->toBe('X')
        ->and(Widget::first()->status)->toBe('locked'); // the scope won, not the posted value

    // The next save resolves the SAME scoped row (no second row), tamper ignored again.
    $this->put('/admin/scoped-setting', ['name' => 'Y', 'status' => 'hack'])->assertRedirect();

    expect(Widget::count())->toBe(1)
        ->and(Widget::first()->name)->toBe('Y')
        ->and(Widget::first()->status)->toBe('locked');
});
