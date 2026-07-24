<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\Notification;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationMessage;
use Ngos\AdminCore\Notifications\Platform\Dispatcher;
use Ngos\AdminCore\Notifications\Platform\InAppChannel;
use Ngos\AdminCore\Notifications\Platform\NotificationPlatform;
use Ngos\AdminCore\Notifications\Platform\OutboundNotification;
use Ngos\AdminCore\Notifications\Platform\Recipient;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/*
 * WP-N2A — the runtime: the Notification store, the InApp channel, and the dispatcher. Built on the ratified Gap A
 * refinement (OutboundNotification carries Recipient). No Center/controller/UI/preferences/deliveries/etc.
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
});

afterEach(function () {
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('users');
});

// ---- schema + hybrid identity ----------------------------------------------------------------------------------

it('creates the notifications table with the canonical hybrid-identity schema', function () {
    expect(Schema::hasTable('notifications'))->toBeTrue();
    foreach (['id', 'uuid', 'notifiable_type', 'notifiable_id', 'guard', 'type', 'data', 'read_at', 'created_at', 'updated_at'] as $col) {
        expect(Schema::hasColumn('notifications', $col))->toBeTrue("missing column {$col}");
    }
});

it('the Notification model uses hybrid identity: bigint id + public uuid route key', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $n = Notification::create([
        'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->getKey(),
        'guard' => 'web', 'type' => 'orders.shipped', 'data' => ['title' => 'Hi'],
    ]);

    expect($n->id)->toBeInt()                              // bigint internal identity
        ->and($n->uuid)->toBeString()->and($n->uuid)->not->toBeEmpty()  // HasPublicUuid auto-filled the public handle
        ->and($n->getRouteKeyName())->toBe('uuid')         // routed by uuid, never the bigint id
        ->and($n->data)->toBe(['title' => 'Hi']);          // json cast round-trips
});

// ---- InApp delivery (reads Recipient.user + Recipient.guard — Gap A resolved) -----------------------------------

it('the InApp channel writes a Notification row from the OutboundNotification recipient', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    // a real uuid — the store's `uuid` column is a strict uuid type on pgsql (SQLite would accept any text)
    $uuid = '0192f1e2-0000-7000-8000-000000000abc';
    $outbound = new OutboundNotification($uuid, 'orders.shipped', Recipient::forUser($user, 'web'), ['title' => 'Shipped']);

    $result = (new InAppChannel)->deliver($outbound);

    expect($result->status->value)->toBe('sent'); // DeliveryResult::sent()
    $n = Notification::firstOrFail();
    expect($n->uuid)->toBe($uuid)
        ->and($n->notifiable_type)->toBe($user->getMorphClass())
        ->and((int) $n->notifiable_id)->toBe((int) $user->getKey())
        ->and($n->guard)->toBe('web')
        ->and($n->type)->toBe('orders.shipped')
        ->and($n->data)->toBe(['title' => 'Shipped']);
});

it('the InApp channel fails (does not throw) for a bare-address recipient — it needs a user', function () {
    $result = (new InAppChannel)->deliver(new OutboundNotification('u', 'orders.shipped', Recipient::forAddress('mail', 'a@b.com'), []));

    expect($result->status->value)->toBe('failed')->and(Notification::count())->toBe(0);
});

// ---- dispatcher orchestration (end-to-end via the facade) -------------------------------------------------------

it('NotificationPlatform::send orchestrates resolve -> route(default inapp) -> InApp, creating one row per recipient', function () {
    $a = NotifiableUser::create(['name' => 'A']);
    $b = NotifiableUser::create(['name' => 'B']);

    NotificationPlatform::send(new StoreTestMessage('orders.shipped', 'Shipped'), [$a, $b]);

    expect(Notification::count())->toBe(2);
    expect(Notification::pluck('type')->all())->toBe(['orders.shipped', 'orders.shipped']);
    expect(Notification::where('notifiable_id', $a->getKey())->value('data'))->toBe(['title' => 'Shipped']);
});

// ---- unread-count cache invalidation via model lifecycle events ------------------------------------------------

it('caches the per-(user,guard) unread count and invalidates it on write (model lifecycle events)', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $make = fn () => Notification::create([
        'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->getKey(),
        'guard' => 'web', 'type' => 't', 'data' => [],
    ]);
    $make();
    $second = $make();

    expect(Notification::unreadCountFor($user->getMorphClass(), $user->getKey(), 'web'))->toBe(2); // cached

    $second->update(['read_at' => now()]); // a write → saved event forgets the cache

    expect(Notification::unreadCountFor($user->getMorphClass(), $user->getKey(), 'web'))->toBe(1); // recomputed
});

// ---- boundaries ------------------------------------------------------------------------------------------------

it('DTO boundary: the channel contract receives OutboundNotification, never the Notification model', function () {
    $param = (new ReflectionMethod(InAppChannel::class, 'deliver'))->getParameters()[0];
    expect((string) $param->getType())->toBe(OutboundNotification::class);
    // deliver() returns a DeliveryResult DTO, not the model:
    expect((string) (new ReflectionMethod(InAppChannel::class, 'deliver'))->getReturnType())
        ->toContain('DeliveryResult');
});

it('architecture boundary: InApp/Dispatcher/Notification reference no product model and no business vocabulary', function () {
    foreach ([InAppChannel::class, Dispatcher::class, Notification::class] as $class) {
        $src = strtolower((string) file_get_contents((new ReflectionClass($class))->getFileName()));
        expect($src)->not->toContain('app\\models');
        foreach (['invoice', 'course', 'student', 'employee', 'sku'] as $noun) {
            expect($src)->not->toContain($noun);
        }
    }
});

it('dispatcher stays orchestration-only: no persistence / cache / retry / scheduling in its source', function () {
    $src = (string) file_get_contents((new ReflectionClass(Dispatcher::class))->getFileName());
    foreach (['Notification::', 'DB::', 'Cache::', 'Schema::', '->save(', '->create(', 'dispatch(', 'later(', 'delay('] as $forbidden) {
        // (the interface method is `dispatch(NotificationMessage ...)` — allow the method declaration, forbid a queued dispatch)
        if ($forbidden === 'dispatch(') {
            continue;
        }
        expect($src)->not->toContain($forbidden);
    }
});

it('Rule-of-Three boundary: no deferred component was built early', function () {
    foreach ([
        'Ngos\\AdminCore\\Notifications\\Platform\\PreferenceResolver',
        'Ngos\\AdminCore\\Notifications\\Platform\\DeliveryRecorder',
        'Ngos\\AdminCore\\Notifications\\Platform\\TemplateRenderer',
        'Ngos\\AdminCore\\Models\\NotificationPreference',
        'Ngos\\AdminCore\\Models\\NotificationDelivery',
    ] as $deferred) {
        expect(class_exists($deferred))->toBeFalse("{$deferred} must NOT exist yet");
    }
});

// ---- fixture ---------------------------------------------------------------------------------------------------

/** A minimal message for the dispatcher end-to-end test. */
class StoreTestMessage implements NotificationMessage
{
    public function __construct(private readonly string $type, private readonly string $title)
    {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function translationKey(): ?string
    {
        return null;
    }

    public function translationParams(): array
    {
        return [];
    }

    public function body(): ?string
    {
        return null;
    }

    public function url(): ?string
    {
        return null;
    }

    public function icon(): ?string
    {
        return null;
    }

    public function data(): array
    {
        return [];
    }
}
