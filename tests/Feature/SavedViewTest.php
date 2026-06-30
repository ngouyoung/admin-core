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
        $t->string('resource');
        $t->string('name');
        $t->json('filters');
        $t->timestamps();
        $t->unique(['user_id', 'resource', 'name']);
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
    SavedView::create(['user_id' => $me->id, 'resource' => 'product', 'name' => 'Mine', 'filters' => []]);
    SavedView::create(['user_id' => $me->id, 'resource' => 'order', 'name' => 'OtherResource', 'filters' => []]);
    SavedView::create(['user_id' => $other->id, 'resource' => 'product', 'name' => 'Theirs', 'filters' => []]);

    $this->actingAs($me);
    $data = $this->getJson('/admin/saved-views?resource=product')->assertOk()->json();

    expect(collect($data)->pluck('name')->all())->toBe(['Mine']); // not the other resource, not the other user
});

it('deletes only the current user view (a crafted id cannot delete another user view)', function () {
    $me = NotifiableUser::create(['name' => 'Me']);
    $other = NotifiableUser::create(['name' => 'Other']);
    $mine = SavedView::create(['user_id' => $me->id, 'resource' => 'product', 'name' => 'Mine', 'filters' => []]);
    $theirs = SavedView::create(['user_id' => $other->id, 'resource' => 'product', 'name' => 'Theirs', 'filters' => []]);

    $this->actingAs($me);

    $this->deleteJson('/admin/saved-views/' . $theirs->id)->assertOk(); // scoped → no-op on another user's row
    expect(SavedView::find($theirs->id))->not->toBeNull();              // untouched

    $this->deleteJson('/admin/saved-views/' . $mine->id)->assertOk();
    expect(SavedView::find($mine->id))->toBeNull();                     // own row deleted
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
