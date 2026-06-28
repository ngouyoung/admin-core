<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\ActionWidgetController;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;
use Ngos\AdminCore\Tests\Fixtures\Widget;

/* Declarative table actions (runAction endpoint) + field-level permissions (strip on write). */

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('status')->nullable();
        $table->string('secret')->nullable();
        $table->integer('sort')->default(0);
        $table->timestamps();
    });
});

// -- runAction endpoint -----------------------------------------------------------------------------

it('404s an unknown action', function () {
    $this->postJson('/admin/action-widgets/action/nope', ['ids' => [1]])->assertNotFound();
});

it('runs the handler over the selected rows and reports the affected count', function () {
    $a = Widget::create(['name' => 'a']);
    $b = Widget::create(['name' => 'b']);
    Widget::create(['name' => 'c']); // not selected

    $this->postJson('/admin/action-widgets/action/publish', ['ids' => [$a->id, $b->id]])
        ->assertOk()
        ->assertJson(['affected' => 2]);

    expect($a->fresh()->status)->toBe('published')
        ->and($b->fresh()->status)->toBe('published')
        ->and(Widget::where('name', 'c')->first()->status)->toBeNull();
});

it('only acts on ids that exist in the resource scope (a foreign id is ignored, not a 404)', function () {
    $a = Widget::create(['name' => 'a']);

    $this->postJson('/admin/action-widgets/action/publish', ['ids' => [$a->id, 999999]])
        ->assertOk()
        ->assertJson(['affected' => 1]);

    expect($a->fresh()->status)->toBe('published');
});

it('lets a handler return a custom toast message', function () {
    Widget::create(['name' => 'a']);
    Widget::create(['name' => 'b']);

    $this->postJson('/admin/action-widgets/action/count', ['ids' => Widget::pluck('id')->all()])
        ->assertOk()
        ->assertJson(['message' => '2 counted']);
});

it('forbids a gated action when the user lacks the permission (server-side, not just hidden)', function () {
    config()->set('admin-core.permission.enabled', true);
    $w = Widget::create(['name' => 'a']);

    // Guest → cannot → 403 even though the route carries no permission middleware.
    $this->postJson('/admin/action-widgets/action/publish', ['ids' => [$w->id]])->assertForbidden();

    expect($w->fresh()->status)->toBeNull(); // nothing ran
});

it('allows a gated action once the user has the permission', function () {
    config()->set('admin-core.permission.enabled', true);
    Gate::define('publish-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'A']));
    $w = Widget::create(['name' => 'a']);

    $this->postJson('/admin/action-widgets/action/publish', ['ids' => [$w->id]])->assertOk();

    expect($w->fresh()->status)->toBe('published');
});

it('runs an ungated (withoutPermission) action even with permissions enabled and no user', function () {
    config()->set('admin-core.permission.enabled', true);
    Widget::create(['name' => 'a']);

    $this->postJson('/admin/action-widgets/action/count', ['ids' => Widget::pluck('id')->all()])
        ->assertOk()
        ->assertJson(['message' => '1 counted']);
});

// -- Field-level permissions ------------------------------------------------------------------------

it('keeps a protected field when permissions are disabled', function () {
    $this->post('/admin/action-widgets', ['name' => 'X', 'secret' => 'topsecret'])->assertRedirect();

    expect(Widget::first()->secret)->not->toBeNull(); // written (hashed)
});

it('strips a protected field on create for a user who lacks its permission', function () {
    config()->set('admin-core.permission.enabled', true); // guest → cannot edit-secret-action-widget

    $this->post('/admin/action-widgets', ['name' => 'X', 'secret' => 'topsecret', 'status' => 'draft'])
        ->assertRedirect();

    $w = Widget::first();
    expect($w->secret)->toBeNull()      // stripped — even though it was in the payload
        ->and($w->status)->toBe('draft'); // an unprotected field is untouched
});

it('strips a protected field on update too', function () {
    config()->set('admin-core.permission.enabled', true);
    $w = Widget::create(['name' => 'Old', 'secret' => null]);

    $this->put('/admin/action-widgets/update/' . $w->id, ['name' => 'New', 'secret' => 'sneaky'])
        ->assertRedirect();

    expect($w->fresh()->secret)->toBeNull()
        ->and($w->fresh()->name)->toBe('New');
});

it('ignores a tampered value for a locked field on update, keeping the stored one', function () {
    config()->set('admin-core.permission.enabled', true); // guest → secret locked
    $w = Widget::create(['name' => 'Old', 'secret' => 'orig']);
    $origHash = $w->getRawOriginal('secret');

    $this->put('/admin/action-widgets/update/' . $w->id, ['name' => 'New', 'secret' => 'hacked'])
        ->assertRedirect();

    expect($w->fresh()->getRawOriginal('secret'))->toBe($origHash); // crafted value never written
});

it('still lets a restricted user save when a locked field is required (stored value merged past validation)', function () {
    config()->set('admin-core.permission.enabled', true);
    config()->set('test.require_secret', true); // secret becomes a required rule
    $w = Widget::create(['name' => 'Old', 'secret' => 'orig']);
    $origHash = $w->getRawOriginal('secret');

    // The form omits secret (field-guard disabled it). Without the merge this would fail "secret is required".
    $this->put('/admin/action-widgets/update/' . $w->id, ['name' => 'New'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($w->fresh()->name)->toBe('New')
        ->and($w->fresh()->getRawOriginal('secret'))->toBe($origHash); // unchanged, not re-hashed
});

it('drops a non-scalar id element instead of 500ing', function () {
    Widget::create(['name' => 'a']);

    // A nested-array element must be filtered out before it reaches whereIn().
    $this->postJson('/admin/action-widgets/action/count', ['ids' => [['x']]])
        ->assertOk()
        ->assertJson(['affected' => 0]);
});

it('lets a permitted user write the protected field', function () {
    config()->set('admin-core.permission.enabled', true);
    Gate::define('edit-secret-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'A']));

    $this->post('/admin/action-widgets', ['name' => 'X', 'secret' => 'topsecret'])->assertRedirect();

    expect(Widget::first()->secret)->not->toBeNull();
});

// -- Config the front-end / kebab menu reads --------------------------------------------------------

it('serialises only the bulk-scoped actions the user may run', function () {
    $controller = app(ActionWidgetController::class);

    // Permissions off → every bulk action is allowed (publish, archive, count, bulk-only); row-only excluded.
    $keys = collect($controller->exposedActionsConfig())->pluck('key')->all();
    expect($keys)->toContain('publish', 'archive', 'count', 'bulk-only')
        ->and($keys)->not->toContain('row-only');

    // Permissions on, guest → only the ungated ones survive.
    config()->set('admin-core.permission.enabled', true);
    $keys = collect($controller->exposedActionsConfig())->pluck('key')->all();
    expect($keys)->toContain('count', 'bulk-only')
        ->and($keys)->not->toContain('publish', 'archive');
});

it('builds per-row action descriptors (row-scoped only, with url + id + confirm)', function () {
    $w = Widget::create(['name' => 'a']);
    $controller = app(ActionWidgetController::class);

    $rows = collect($controller->exposedRowActions($w));
    $labels = $rows->pluck('label')->all();

    expect($labels)->toContain('Publish')           // row + bulk action shows on the row
        ->and($rows->firstWhere('label', 'Publish'))->toMatchArray(['id' => $w->getRouteKey()])
        ->and($rows->firstWhere('label', 'Publish')['url'])->toContain('/action/publish')
        ->and($rows->firstWhere('label', 'Publish')['confirm'])->not->toBeNull();

    // bulk-only must NOT appear in the row menu; row-only must.
    expect($labels)->not->toContain('Bulk Only')
        ->and($labels)->toContain('Row Only');
});

it('computes the denied-field set from fieldPermissions + the current user', function () {
    $controller = app(ActionWidgetController::class);

    expect($controller->exposedDeniedFields())->toBe([]); // permissions off → nothing denied

    config()->set('admin-core.permission.enabled', true);
    expect($controller->exposedDeniedFields())->toBe(['secret']); // guest → denied

    Gate::define('edit-secret-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'A']));
    expect($controller->exposedDeniedFields())->toBe([]); // permitted → allowed
});
