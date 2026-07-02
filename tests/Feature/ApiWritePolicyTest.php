<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;
use Ngos\AdminCore\Tests\Fixtures\Widget;

/*
 * The JSON API must enforce the SAME write policy as the web surface: field-level permissions, the
 * transition-only state column, and locked-state refusal — not just the coarse route permission.
 * (PolicyWidgetApiController declares fieldPermissions[secret], transitions(post), lockedStates[posted].)
 */

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('status')->default('draft');
        $t->string('secret')->nullable();
        $t->string('photo')->nullable();
        $t->integer('sort')->default(0);
        $t->timestamps();
    });
});

it('API store strips a field the user lacks permission to write', function () {
    config()->set('admin-core.permission.enabled', true);
    $this->actingAs(new NotifiableUser(['name' => 'U'])); // holds no permissions → cannot edit-secret-widget

    $this->postJson('/api/policy-widgets', ['name' => 'X', 'secret' => 'top-secret'])->assertStatus(201);

    expect(Widget::first()->secret)->toBeNull(); // stripped server-side, not persisted
});

it('API store keeps a field the user IS permitted to write', function () {
    config()->set('admin-core.permission.enabled', true);
    Gate::define('edit-secret-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'U']));

    $this->postJson('/api/policy-widgets', ['name' => 'X', 'secret' => 'ok'])->assertStatus(201);

    expect(Widget::first()->secret)->not->toBeNull(); // permitted → persisted (hashed)
});

it('API store refuses to set the state column directly (state machine → transition-only)', function () {
    $this->postJson('/api/policy-widgets', ['name' => 'X', 'status' => 'posted'])->assertStatus(201);

    expect(Widget::first()->status)->toBe('draft'); // status stripped → DB default, not the injected "posted"
});

it('API update refuses to write a record in a locked state (403)', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'posted']); // posted is locked

    $this->putJson('/api/policy-widgets/' . $w->id, ['name' => 'Changed'])->assertForbidden();

    expect($w->fresh()->name)->toBe('Doc'); // unchanged
});

it('API destroy refuses to delete a record in a locked state (403)', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'posted']);

    $this->deleteJson('/api/policy-widgets/' . $w->id)->assertForbidden();

    expect(Widget::find($w->id))->not->toBeNull(); // still there
});

it('API update allows a write when the record is NOT in a locked state', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']);

    $this->putJson('/api/policy-widgets/' . $w->id, ['name' => 'Changed'])->assertOk();

    expect($w->fresh()->name)->toBe('Changed');
});
