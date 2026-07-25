<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\Notification;
use Ngos\AdminCore\Notifications\AdminNotification;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationMessage;
use Ngos\AdminCore\Notifications\Platform\NotificationPlatform;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/*
 * In-app notifications (WP-N3 cutover): Route::adminCoreNotifications() registers the routes and the
 * NotificationController manages the current user's notifications on the Notification Platform's hybrid store
 * ({@see Notification}) — no longer Laravel database notifications. Producers send via NotificationPlatform::send().
 */

beforeEach(function () {
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->timestamps();
    });
    (require dirname(__DIR__, 2) . '/database/migrations/2025_01_01_000008_create_notifications_table.php')->up();
    Cache::flush();

    Route::middleware('web')->prefix('admin')->name('admin.')
        ->group(fn () => Route::adminCoreNotifications());
});

afterEach(function () {
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('users');
});

/** Seed a notification in the hybrid store (guard null, as the platform's send() surface writes). */
function seedNotification(NotifiableUser $user, ?string $readAt = null, ?array $data = null, ?\Illuminate\Support\Carbon $createdAt = null): Notification
{
    $n = new Notification([
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'guard' => null,
        'type' => AdminNotification::TYPE,
        'data' => $data ?? ['title' => 'Hello', 'body' => 'A test notification'],
        'read_at' => $readAt,
    ]);
    if ($createdAt) {
        $n->created_at = $createdAt; // set before save so Eloquent keeps it instead of stamping now()
        $n->updated_at = $createdAt;
    }
    $n->save();

    return $n;
}

// ---- routes -----------------------------------------------------------------------------------------------------

it('registers the notification routes via Route::adminCoreNotifications()', function () {
    Route::getRoutes()->refreshNameLookups();
    expect(Route::has('admin.notifications.index'))->toBeTrue()
        ->and(Route::has('admin.notifications.unread'))->toBeTrue()
        ->and(Route::has('admin.notifications.read'))->toBeTrue()
        ->and(Route::has('admin.notifications.readAll'))->toBeTrue()
        ->and(Route::has('admin.notifications.destroy'))->toBeTrue();
});

// ---- unread-count JSON endpoint (realtime store re-sync; WP-N6A) -------------------------------------------------

it('the unread endpoint returns the current user\'s authoritative unread count as JSON, scoped to the owner', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    seedNotification($user);
    seedNotification($user);
    seedNotification($user, readAt: now()->toDateTimeString()); // already read — not counted
    seedNotification(NotifiableUser::create(['name' => 'B']));   // another user's — never counted

    $this->actingAs($user)->getJson(route('admin.notifications.unread'))
        ->assertOk()
        ->assertExactJson(['unread' => 2]);
});

it('the unread endpoint scopes to the route\'s portal guard, not the default guard', function () {
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);
    Route::middleware('web')->prefix('merchant')->name('merchant.')
        ->group(fn () => Route::adminCoreNotifications('merchant'));
    Route::getRoutes()->refreshNameLookups();

    $user = NotifiableUser::create(['name' => 'M']);
    seedNotification($user);

    // Authenticated ONLY on the merchant guard — the real portal shape (the default 'web' guard has no user).
    $merchantUnread = Route::getRoutes()->getByName('merchant.notifications.unread');
    $req = \Illuminate\Http\Request::create('/merchant/notifications/unread');
    $req->setRouteResolver(fn () => $merchantUnread);
    auth()->guard('merchant')->setUser($user);

    $json = app()->call([app(\Ngos\AdminCore\Http\Controllers\NotificationController::class), 'unread'], ['request' => $req]);
    expect($json->getData(true))->toBe(['unread' => 1]);
});

// ---- read / mark-read (authorization: own only) -----------------------------------------------------------------

it('marks a single notification read and only touches the current user', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $other = NotifiableUser::create(['name' => 'B']);
    $mine = seedNotification($user);
    $theirs = seedNotification($other);

    $this->actingAs($user)->post(route('admin.notifications.read', $mine->uuid))->assertRedirect();

    expect($mine->fresh()->read_at)->not->toBeNull()
        ->and($theirs->fresh()->read_at)->toBeNull(); // untouched
});

it('cannot read another user\'s notification — 404, scoped by owner', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $theirs = seedNotification(NotifiableUser::create(['name' => 'B']));

    $this->actingAs($user)->post(route('admin.notifications.read', $theirs->uuid))->assertNotFound();
    expect($theirs->fresh()->read_at)->toBeNull();
});

it('scopes the inbox to the route\'s portal guard, not the default guard (audit-11)', function () {
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);
    Route::middleware('web')->prefix('merchant')->name('merchant.')
        ->group(fn () => Route::adminCoreNotifications('merchant'));
    Route::getRoutes()->refreshNameLookups();

    $user = NotifiableUser::create(['name' => 'M']);
    $mine = seedNotification($user);

    // Authenticated ONLY on the merchant guard (the default 'web' guard has no user — the real portal shape).
    auth()->guard('merchant')->setUser($user);

    // The unread counter resolves the merchant user on the portal route and reports its (guard=null) rows — a
    // regression that filtered by the route guard ('merchant') would return 0 against the null-guard rows.
    $merchantIndex = Route::getRoutes()->getByName('merchant.notifications.index');
    $req = \Illuminate\Http\Request::create('/merchant/notifications');
    $req->setRouteResolver(fn () => $merchantIndex);
    $view = app()->call([app(\Ngos\AdminCore\Http\Controllers\NotificationController::class), 'index'], ['request' => $req]);
    expect($view->getData()['unreadCount'])->toBe(1);

    $this->post(route('merchant.notifications.read', $mine->uuid))->assertRedirect(); // resolved on the merchant guard
    expect($mine->fresh()->read_at)->not->toBeNull();
});

it('marks all the user\'s notifications read — and only the current user\'s', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    seedNotification($user);
    seedNotification($user);
    $theirs = seedNotification(NotifiableUser::create(['name' => 'B'])); // another user's unread must survive

    $this->actingAs($user)->post(route('admin.notifications.readAll'))->assertRedirect();

    expect(Notification::unreadCountFor($user->getMorphClass(), $user->getKey(), null))->toBe(0) // mine cleared
        ->and($theirs->fresh()->read_at)->toBeNull();                                            // theirs untouched (scoped)
});

// ---- delete (authorization: own only) ---------------------------------------------------------------------------

it('deletes a notification', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $n = seedNotification($user);

    $this->actingAs($user)->delete(route('admin.notifications.destroy', $n->uuid))->assertRedirect();

    expect(Notification::whereKey($n->id)->exists())->toBeFalse();
});

it('cannot delete another user\'s notification', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $theirs = seedNotification(NotifiableUser::create(['name' => 'B']));

    $this->actingAs($user)->delete(route('admin.notifications.destroy', $theirs->uuid))->assertRedirect();

    expect(Notification::whereKey($theirs->id)->exists())->toBeTrue(); // untouched
});

// ---- unread counter + cache invalidation (WP-N2A cache) ---------------------------------------------------------

it('reads the unread counter from the platform cache and invalidates it on mark-read', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    seedNotification($user);
    $n = seedNotification($user);
    $count = fn () => Notification::unreadCountFor($user->getMorphClass(), $user->getKey(), null);

    expect($count())->toBe(2); // cached

    $this->actingAs($user)->post(route('admin.notifications.read', $n->uuid))->assertRedirect();

    expect($count())->toBe(1); // the read fired the model saved event → cache recomputed
});

it('mark-all-read invalidates the unread-count cache (not just a mass update)', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    seedNotification($user);
    seedNotification($user);
    $count = fn () => Notification::unreadCountFor($user->getMorphClass(), $user->getKey(), null);
    expect($count())->toBe(2);

    $this->actingAs($user)->post(route('admin.notifications.readAll'))->assertRedirect();

    expect($count())->toBe(0);
});

it('delete invalidates the unread-count cache', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $n = seedNotification($user);
    $count = fn () => Notification::unreadCountFor($user->getMorphClass(), $user->getKey(), null);
    expect($count())->toBe(1);

    $this->actingAs($user)->delete(route('admin.notifications.destroy', $n->uuid))->assertRedirect();

    expect($count())->toBe(0);
});

// ---- producer / message (AdminNotification through the platform) ------------------------------------------------

it('AdminNotification is a platform NotificationMessage that maps its fields', function () {
    $m = new AdminNotification(title: 'T', message: 'B', url: '/u', icon: 'bi-x', extra: ['k' => 1]);

    expect($m)->toBeInstanceOf(NotificationMessage::class)
        ->and($m->type())->toBe(AdminNotification::TYPE)
        ->and($m->title())->toBe('T')
        ->and($m->body())->toBe('B')          // the supporting line maps to body()
        ->and($m->url())->toBe('/u')
        ->and($m->icon())->toBe('bi-x')
        ->and($m->data())->toBe(['k' => 1])
        ->and($m->translationKey())->toBeNull();
});

it('AdminNotification sent through the platform stores title/body/url/icon (+extra) in the store', function () {
    $user = NotifiableUser::create(['name' => 'A']);

    NotificationPlatform::send(new AdminNotification(
        title: 'Shipped', message: 'On its way', url: '/admin/orders/1', icon: 'bi-truck', extra: ['order_id' => 7],
    ), $user);

    $n = Notification::where('notifiable_id', $user->getKey())->firstOrFail();
    expect($n->type)->toBe(AdminNotification::TYPE)
        ->and($n->guard)->toBeNull()
        ->and($n->data)->toMatchArray([
            'title' => 'Shipped',
            'body' => 'On its way',   // message() → the content's `body` key
            'url' => '/admin/orders/1',
            'icon' => 'bi-truck',
            'order_id' => 7,          // extra is merged into the payload
        ]);
});

// ---- Notification Center (index: newest first + pagination) -----------------------------------------------------

it('the center lists the user\'s notifications newest-first, paginated at 20, with the unread count', function () {
    // The center view @extends the host's backend.layouts.app (absent in the package test app), so assert on the
    // controller's view DATA — the paginator + unread count — which is the Center's actual logic.
    $user = NotifiableUser::create(['name' => 'A']);
    for ($i = 1; $i <= 22; $i++) {
        seedNotification($user, data: ['title' => "N{$i}"], createdAt: now()->subMinutes(60 - $i)); // N22 newest
    }

    $this->actingAs($user);
    $view = app()->call([app(\Ngos\AdminCore\Http\Controllers\NotificationController::class), 'index'], [
        'request' => \Illuminate\Http\Request::create('/admin/notifications'),
    ]);
    $notifications = $view->getData()['notifications'];

    expect($notifications->perPage())->toBe(20)
        ->and($notifications->total())->toBe(22)
        ->and($notifications->count())->toBe(20)                    // page 1 holds 20
        ->and($notifications->first()->data['title'])->toBe('N22')  // newest first
        ->and($notifications->hasMorePages())->toBeTrue()           // paginated
        ->and($view->getData()['unreadCount'])->toBe(22);
});

it('renders the center HTML: shows the body line and uses the configured route prefix (not a hardcoded admin.)', function () {
    // Stub the host layout the view @extends so it can compile (as GeneratorExecutionTest does), then render it —
    // guards two recurring bug classes in the view itself: the data['body'] key (WP-N3) and the route name prefix.
    \Illuminate\Support\Facades\File::ensureDirectoryExists(resource_path('views/backend/layouts'));
    \Illuminate\Support\Facades\File::put(
        resource_path('views/backend/layouts/app.blade.php'),
        '<!doctype html><html><body>@yield(\'contents\')</body></html>'
    );

    $user = NotifiableUser::create(['name' => 'A']);
    seedNotification($user, data: ['title' => 'Hi', 'body' => 'A rendered supporting line', 'icon' => 'bi-bell']);

    $this->actingAs($user)->get(route('admin.notifications.index'))->assertOk()
        ->assertSee('A rendered supporting line')      // reads data['body'] (a data['message'] regression fails here)
        ->assertSee('/admin/notifications', false);    // config name_prefix, never a hardcoded 'admin.'

    \Illuminate\Support\Facades\File::delete(resource_path('views/backend/layouts/app.blade.php'));
});

// ---- bell -------------------------------------------------------------------------------------------------------

it('the bell honours the configurable route name_prefix (not a hardcoded admin.)', function () {
    Route::middleware('web')->prefix('panel')->name('panel.')
        ->group(fn () => Route::adminCoreNotifications());
    Route::getRoutes()->refreshNameLookups();
    config()->set('admin-core.route.name_prefix', 'panel.');

    $user = NotifiableUser::create(['name' => 'A']);
    seedNotification($user); // unread → the read/readAll/see-all links all render

    $this->actingAs($user);
    $html = Blade::render('<x-admin-core::notifications-bell />');

    expect($html)->toContain('/panel/notifications')->not->toContain('/admin/notifications');
});

it('renders the bell with a bounded (cached) count + 6-item preview, never loading every unread row', function () {
    Route::getRoutes()->refreshNameLookups();

    $user = NotifiableUser::create(['name' => 'A']);
    for ($i = 0; $i < 20; $i++) {
        seedNotification($user); // 20 unread
    }
    Cache::flush(); // cold cache → the count query runs this render
    $this->actingAs($user);

    DB::enableQueryLog();
    $html = Blade::render('<x-admin-core::notifications-bell />');
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => str_replace(['"', '`'], '', $q));
    DB::disableQueryLog();

    // No unbounded row SELECT — the preview is LIMIT 6, and the badge is a COUNT. (Quoting normalised per engine — TS-1.)
    $selects = $queries->filter(fn ($q) => str_contains($q, 'from notifications') && str_contains($q, 'read_at'));
    expect($selects->filter(fn ($q) => str_contains($q, 'select *')))
        ->each(fn ($q) => $q->toContain('limit 6'))
        ->and($selects->contains(fn ($q) => str_contains($q, 'count(')))->toBeTrue();
    // Badge shows the capped label; exactly 6 preview forms render (each posts to a read route).
    expect($html)->toContain('data-count="20"')->toContain('9+')
        ->and(substr_count($html, '/read"'))->toBe(6); // read-all is `/read-all"`, so this counts only the previews
});

// ---- bell realtime wiring (WP-N6A) ------------------------------------------------------------------------------

it('the bell exposes the morph-identity channel + sync url ONLY when broadcast is enabled', function () {
    Route::getRoutes()->refreshNameLookups();
    $user = NotifiableUser::create(['name' => 'A']);
    $this->actingAs($user);

    // broadcast OFF (default) → a static bell, no realtime attributes, no channel name leaked
    config()->set('admin-core.notifications.broadcast.enabled', false);
    $off = Blade::render('<x-admin-core::notifications-bell />');
    expect($off)->not->toContain('data-ac-channel')->not->toContain('data-ac-unread-url');

    // broadcast ON → the SAME channel the server publishes/authorizes, plus the store re-sync endpoint
    config()->set('admin-core.notifications.broadcast.enabled', true);
    $channel = app(\Ngos\AdminCore\Notifications\Platform\Broadcast\ChannelNameResolver::class)->forUser($user);
    $on = Blade::render('<x-admin-core::notifications-bell />');
    expect($on)->toContain('data-ac-channel="' . $channel . '"')
        ->toContain('data-ac-unread-url="')
        ->toContain('/admin/notifications/unread') // the store re-sync endpoint (route() renders it absolute)
        // the badge is always present (hidden at zero) so realtime.js can reveal + bump it
        ->toContain('data-ac-bell-count');
});

// ---- open-redirect guard on the stored url ----------------------------------------------------------------------

it('follows a notification url only when relative/same-host (no open redirect)', function () {
    $user = NotifiableUser::create(['name' => 'A']);

    // an external/absolute url is NOT followed — falls back to back()
    $ext = seedNotification($user, data: ['url' => 'https://evil.example/x']);
    $resp = $this->actingAs($user)->post(route('admin.notifications.read', $ext->uuid))->assertRedirect();
    expect($resp->headers->get('Location'))->not->toContain('evil.example');

    // a relative (same-app) url IS followed
    $rel = seedNotification($user, data: ['url' => '/admin/orders/9']);
    $this->actingAs($user)->post(route('admin.notifications.read', $rel->uuid))->assertRedirect('/admin/orders/9');
});
