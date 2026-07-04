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

it('updates the row a concurrent first save created instead of inserting a duplicate (race)', function () {
    // Simulate a concurrent request committing the FIRST row mid-request: record() has already returned
    // an unsaved instance when the FormRequest resolves — create the row exactly there. The write must
    // re-resolve and UPDATE that row, not save its stale unsaved instance as a second one.
    app()->resolving(\Ngos\AdminCore\Tests\Fixtures\StoreWidgetRequest::class, function () {
        if (Widget::count() === 0) {
            Widget::create(['name' => 'Winner']);
        }
    });

    $this->put('/admin/settings', ['name' => 'Updated by loser'])->assertRedirect();

    expect(Widget::count())->toBe(1)
        ->and(Widget::first()->name)->toBe('Updated by loser');
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

it('enforces the state machine — refuses to edit a LOCKED singleton and strips a direct status write', function () {
    // Strip the status column from a normal save: status moves only via a transition, never the form.
    Widget::create(['name' => 'Doc', 'status' => 'open']);
    $this->put('/admin/state-setting', ['name' => 'Edited', 'status' => 'locked'])->assertRedirect();
    expect(Widget::first()->status)->toBe('open')      // status NOT changed by the form (stripped)
        ->and(Widget::first()->name)->toBe('Edited');  // the rest of the edit applied

    // A record already in a LOCKED state is read-only — the update is refused (403).
    Widget::query()->update(['status' => 'locked']);
    $this->put('/admin/state-setting', ['name' => 'Changed'])->assertForbidden();
    expect(Widget::first()->name)->toBe('Edited');     // unchanged
});
