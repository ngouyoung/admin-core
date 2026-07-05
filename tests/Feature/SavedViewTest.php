<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\SavedView;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/* Per-user saved list views: the index/store/destroy endpoints (Route::adminCoreSavedViews, registered in
   TestCase). Every row is scoped to the current user — a crafted id/resource can't reach another user's. */

beforeEach(function () {
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->timestamps();
    });
    Schema::create('saved_views', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->string('guard')->nullable();
        $t->string('resource');
        $t->string('name');
        $t->json('filters');
        $t->timestamps();
        $t->unique(['user_id', 'guard', 'resource', 'name']);
    });
});

afterEach(function () {
    Schema::dropIfExists('saved_views');
    Schema::dropIfExists('users');
});

it('saves a named view scoped to the current user', function () {
    $user = NotifiableUser::create(['name' => 'U']);
    $this->actingAs($user);

    $this->postJson('/admin/saved-views', [
        'resource' => 'product',
        'name' => 'Active',
        'filters' => ['status' => 'active', 'created_at' => ['from' => '2026-01-01']],
    ])->assertOk()->assertJson(['name' => 'Active']);

    $row = SavedView::first();
    expect($row->user_id)->toBe($user->id)
        ->and($row->resource)->toBe('product')
        ->and($row->filters)->toBe(['status' => 'active', 'created_at' => ['from' => '2026-01-01']]);
});

it('overwrites a view of the same name instead of duplicating', function () {
    $user = NotifiableUser::create(['name' => 'U']);
    $this->actingAs($user);

    $this->postJson('/admin/saved-views', ['resource' => 'product', 'name' => 'V', 'filters' => ['status' => 'a']])->assertOk();
    $this->postJson('/admin/saved-views', ['resource' => 'product', 'name' => 'V', 'filters' => ['status' => 'b']])->assertOk();

    expect(SavedView::where('resource', 'product')->where('name', 'V')->count())->toBe(1)
        ->and(SavedView::first()->filters)->toBe(['status' => 'b']); // overwritten
});

it('lists only the current user views for the requested resource', function () {
    $me = NotifiableUser::create(['name' => 'Me']);
    $other = NotifiableUser::create(['name' => 'Other']);
    SavedView::create(['user_id' => $me->id, 'guard' => 'web', 'resource' => 'product', 'name' => 'Mine', 'filters' => []]);
    SavedView::create(['user_id' => $me->id, 'guard' => 'web', 'resource' => 'order', 'name' => 'OtherResource', 'filters' => []]);
    SavedView::create(['user_id' => $other->id, 'guard' => 'web', 'resource' => 'product', 'name' => 'Theirs', 'filters' => []]);

    $this->actingAs($me);
    $data = $this->getJson('/admin/saved-views?resource=product')->assertOk()->json();

    expect(collect($data)->pluck('name')->all())->toBe(['Mine']); // not the other resource, not the other user
});

it('deletes only the current user view (a crafted id cannot delete another user view)', function () {
    $me = NotifiableUser::create(['name' => 'Me']);
    $other = NotifiableUser::create(['name' => 'Other']);
    $mine = SavedView::create(['user_id' => $me->id, 'guard' => 'web', 'resource' => 'product', 'name' => 'Mine', 'filters' => []]);
    $theirs = SavedView::create(['user_id' => $other->id, 'guard' => 'web', 'resource' => 'product', 'name' => 'Theirs', 'filters' => []]);

    $this->actingAs($me);

    $this->deleteJson('/admin/saved-views/' . $theirs->id)->assertOk(); // scoped → no-op on another user's row
    expect(SavedView::find($theirs->id))->not->toBeNull();              // untouched

    $this->deleteJson('/admin/saved-views/' . $mine->id)->assertOk();
    expect(SavedView::find($mine->id))->toBeNull();                     // own row deleted
});

it('scopes by guard so a portal user cannot read/overwrite/delete another guard user\'s view of the same id', function () {
    // Multi-portal installs have independent user tables per guard, so id 5 on 'web' and id 5 on 'merchant'
    // are different people. Scoping by user_id alone was a cross-portal IDOR — the guard discriminator closes it.
    config()->set('admin-core.permission.guards', ['merchant' => []]);
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);

    $alice = NotifiableUser::create(['name' => 'Alice']); // an admin on the 'web' guard
    $this->actingAs($alice, 'web')
        ->postJson('/admin/saved-views', ['resource' => 'product', 'name' => 'Low stock', 'filters' => ['a' => 1]])
        ->assertOk();
    $aliceRow = SavedView::first();
    expect($aliceRow->guard)->toBe('web');

    // A merchant-guard user with the SAME numeric id (here the same row, acted via the merchant guard — the
    // point is the guard discriminator, not a second table) must be fully walled off from Alice's view.
    $this->actingAs($alice, 'merchant');

    // index: Alice's web view does NOT leak to the merchant guard.
    expect($this->getJson('/admin/saved-views?resource=product')->assertOk()->json())->toBe([]);

    // store: the same (resource, name) makes a SEPARATE merchant row — it does not overwrite Alice's filters.
    $this->postJson('/admin/saved-views', ['resource' => 'product', 'name' => 'Low stock', 'filters' => ['b' => 2]])->assertOk();
    expect(SavedView::count())->toBe(2)                       // distinct web + merchant rows
        ->and($aliceRow->fresh()->filters)->toBe(['a' => 1]); // Alice's untouched

    // destroy: the merchant user cannot delete Alice's web row.
    $this->deleteJson('/admin/saved-views/' . $aliceRow->id)->assertOk();
    expect(SavedView::find($aliceRow->id))->not->toBeNull();
});

it('requires a resource and name', function () {
    $this->actingAs(NotifiableUser::create(['name' => 'U']));

    $this->postJson('/admin/saved-views', ['filters' => []])->assertStatus(422);
});

it('ignores a forged user_id in the payload (always the authed user)', function () {
    $user = NotifiableUser::create(['name' => 'U']);
    $this->actingAs($user);

    // A crafted user_id must NOT forge a row for someone else — it's set from auth(), not the request.
    $this->postJson('/admin/saved-views', ['resource' => 'product', 'name' => 'V', 'filters' => [], 'user_id' => 9999])
        ->assertOk();

    expect(SavedView::first()->user_id)->toBe($user->id);
});

it('refuses an unauthenticated request (personal state, no user_id=null rows)', function () {
    // No actingAs — a guest reaching the endpoints (e.g. an admin group without auth middleware) is refused.
    $this->postJson('/admin/saved-views', ['resource' => 'product', 'name' => 'V', 'filters' => []])->assertForbidden();
    $this->getJson('/admin/saved-views?resource=product')->assertForbidden();
    $this->deleteJson('/admin/saved-views/1')->assertForbidden();

    expect(SavedView::count())->toBe(0);
});
