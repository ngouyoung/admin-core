<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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

it('deletes a notification', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $id = seedNotification($user);

    $this->actingAs($user)->delete(route('admin.notifications.destroy', $id))->assertRedirect();

    expect(DB::table('notifications')->where('id', $id)->exists())->toBeFalse();
});
