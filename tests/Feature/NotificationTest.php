<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Notifications\AdminNotification;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/*
 * In-app notifications: Route::adminCoreNotifications() registers the routes, and the
 * NotificationController manages the current user's Laravel database notifications.
 */

beforeEach(function () {
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->timestamps();
    });
    Schema::create('notifications', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('type');
        $t->morphs('notifiable');
        $t->longText('data');
        $t->timestamp('read_at')->nullable();
        $t->timestamps();
    });

    Route::middleware('web')->prefix('admin')->name('admin.')
        ->group(fn () => Route::adminCoreNotifications());
});

afterEach(function () {
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('users');
});

function seedNotification(NotifiableUser $user, ?string $readAt = null): string
{
    $id = (string) \Illuminate\Support\Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'App\\Notifications\\Demo',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(['title' => 'Hello', 'message' => 'A test notification']),
        'read_at' => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('registers the notification routes via Route::adminCoreNotifications()', function () {
    Route::getRoutes()->refreshNameLookups(); // populate the name list (no request fired this test)
    expect(Route::has('admin.notifications.index'))->toBeTrue()
        ->and(Route::has('admin.notifications.read'))->toBeTrue()
        ->and(Route::has('admin.notifications.readAll'))->toBeTrue()
        ->and(Route::has('admin.notifications.destroy'))->toBeTrue();
});

it('marks a single notification read and only touches the current user', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $other = NotifiableUser::create(['name' => 'B']);
    $mine = seedNotification($user);
    $theirs = seedNotification($other);

    $this->actingAs($user)->post(route('admin.notifications.read', $mine))->assertRedirect();

    expect(DB::table('notifications')->where('id', $mine)->value('read_at'))->not->toBeNull()
        ->and(DB::table('notifications')->where('id', $theirs)->value('read_at'))->toBeNull(); // untouched
});

it('marks all the user\'s notifications read', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    seedNotification($user);
    seedNotification($user);

    $this->actingAs($user)->post(route('admin.notifications.readAll'))->assertRedirect();

    expect(DB::table('notifications')->whereNull('read_at')->count())->toBe(0);
});

it('AdminNotification stores title/message/url/icon (+extra) as a database notification', function () {
    $user = NotifiableUser::create(['name' => 'A']);

    $user->notify(new AdminNotification(
        title: 'Shipped',
        message: 'On its way',
        url: '/admin/orders/1',
        icon: 'bi-truck',
        extra: ['order_id' => 7],
    ));

    $row = DB::table('notifications')->where('notifiable_id', $user->getKey())->first();
    expect($row)->not->toBeNull()
        ->and($row->type)->toBe(AdminNotification::class)
        ->and(json_decode((string) $row->data, true))->toMatchArray([
            'title' => 'Shipped',
            'message' => 'On its way',
            'url' => '/admin/orders/1',
            'icon' => 'bi-truck',
            'order_id' => 7, // extra is merged into the payload
        ]);
});

it('AdminNotification broadcasts only when realtime is on (or forced per-notification)', function () {
    $user = NotifiableUser::create(['name' => 'A']);

    config()->set('admin-core.notifications.realtime', false);
    expect((new AdminNotification('x'))->via($user))->toBe(['database']);          // default: in-app only

    config()->set('admin-core.notifications.realtime', true);
    expect((new AdminNotification('x'))->via($user))->toBe(['database', 'broadcast']); // realtime on

    // Per-notification override beats the config either way.
    expect((new AdminNotification('x', broadcast: false))->via($user))->toBe(['database']);
    config()->set('admin-core.notifications.realtime', false);
    expect((new AdminNotification('x', broadcast: true))->via($user))->toBe(['database', 'broadcast']);
});

it('AdminNotification broadcast payload matches the stored data', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $message = (new AdminNotification(title: 'Shipped', message: 'On its way', url: '/x', icon: 'bi-truck', extra: ['id' => 9]))
        ->toBroadcast($user);

    expect($message)->toBeInstanceOf(\Illuminate\Notifications\Messages\BroadcastMessage::class)
        ->and($message->data)->toMatchArray([
            'title' => 'Shipped', 'message' => 'On its way', 'url' => '/x', 'icon' => 'bi-truck', 'id' => 9,
        ]);
});

it('deletes a notification', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $id = seedNotification($user);

    $this->actingAs($user)->delete(route('admin.notifications.destroy', $id))->assertRedirect();

    expect(DB::table('notifications')->where('id', $id)->exists())->toBeFalse();
});

it('the bell honours the configurable route name_prefix (not a hardcoded admin.)', function () {
    // A consumer that customises the prefix registers the routes under it…
    Route::middleware('web')->prefix('panel')->name('panel.')
        ->group(fn () => Route::adminCoreNotifications());
    Route::getRoutes()->refreshNameLookups();
    config()->set('admin-core.route.name_prefix', 'panel.');

    $user = NotifiableUser::create(['name' => 'A']);
    seedNotification($user); // unread → the read/readAll/see-all links all render

    $this->actingAs($user);
    $html = \Illuminate\Support\Facades\Blade::render('<x-admin-core::notifications-bell />');

    // …so the bell must point at the panel-prefixed routes, never the hardcoded admin. ones.
    expect($html)->toContain('/panel/notifications')->not->toContain('/admin/notifications');
});

it('follows a notification url only when relative/same-host (no open redirect)', function () {
    $user = NotifiableUser::create(['name' => 'A']);

    // an external/absolute url is NOT followed — falls back to back()
    $ext = seedNotification($user);
    DB::table('notifications')->where('id', $ext)->update(['data' => json_encode(['url' => 'https://evil.example/x'])]);
    $resp = $this->actingAs($user)->post(route('admin.notifications.read', $ext))->assertRedirect();
    expect($resp->headers->get('Location'))->not->toContain('evil.example');

    // a relative (same-app) url IS followed
    $rel = seedNotification($user);
    DB::table('notifications')->where('id', $rel)->update(['data' => json_encode(['url' => '/admin/orders/9'])]);
    $this->actingAs($user)->post(route('admin.notifications.read', $rel))->assertRedirect('/admin/orders/9');
});
