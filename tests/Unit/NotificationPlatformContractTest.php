<?php

use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationChannel;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationDispatcher;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationMessage;
use Ngos\AdminCore\Notifications\Platform\DeliveryResult;
use Ngos\AdminCore\Notifications\Platform\DeliveryStatus;
use Ngos\AdminCore\Notifications\Platform\NotificationChannelManager;
use Ngos\AdminCore\Notifications\Platform\NotificationPlatform;
use Ngos\AdminCore\Notifications\Platform\NotificationTypeRegistry;
use Ngos\AdminCore\Notifications\Platform\OutboundNotification;
use Ngos\AdminCore\Notifications\Platform\PendingNotification;
use Ngos\AdminCore\Notifications\Platform\Recipient;

/*
 * WP-N1 — Notification Platform contracts & registries. These are CONTRACT tests: they pin the frozen public
 * surface (Notification Platform v2.1) and the two open registries. No storage, model, dispatcher logic, channel,
 * or UI is implemented in WP-N1, so there are no feature/integration tests here — only the contracts.
 */

// ---- Channel registry (open, config-seeded, throw-on-unknown) — mirrors TranslationManager ----------------------

it('registers a channel via extend() and resolves it through make() (open registry)', function () {
    $manager = new NotificationChannelManager;
    expect(fn () => $manager->make('fake'))->toThrow(InvalidArgumentException::class); // not registered yet

    $manager->extend('fake', fn () => new FakeChannel);

    expect($manager->make('fake'))->toBeInstanceOf(NotificationChannel::class); // now resolvable through the registry
});

it('throws on an unknown channel name (throw-on-unknown)', function () {
    expect(fn () => (new NotificationChannelManager)->make('nope'))
        ->toThrow(InvalidArgumentException::class, 'Unknown admin-core notification channel [nope]');
});

it('seeds channel drivers from config', function () {
    config(['admin-core.notifications.channels' => ['cfg' => FakeChannel::class]]);

    $manager = new NotificationChannelManager;
    expect($manager->make('cfg'))->toBeInstanceOf(FakeChannel::class);
});

// ---- Dispatcher-independent delivery contract -------------------------------------------------------------------

it('delivers an OutboundNotification through a channel with NO dispatcher involved (dispatcher-independent)', function () {
    $channel = new FakeChannel;
    $outbound = new OutboundNotification('uuid-1', 'orders.shipped', 'a@b.com', ['title' => 'Hi']);

    $result = $channel->deliver($outbound);

    expect($result)->toBeInstanceOf(DeliveryResult::class)
        ->and($result->status)->toBe(DeliveryStatus::Sent)
        ->and($channel->received)->toBe($outbound); // deliver() actually ran with our DTO — no dispatcher
});

// ---- DTO / value-object immutability ----------------------------------------------------------------------------

it('OutboundNotification is an immutable read-only DTO carrying the uuid (never a bigint id)', function () {
    $o = new OutboundNotification('uuid-9', 'orders.shipped', 'a@b.com', ['title' => 'Hi']);

    expect($o->notificationUuid)->toBe('uuid-9')->and($o->address)->toBe('a@b.com')
        ->and($o->content)->toBe(['title' => 'Hi']);
    expect(fn () => $o->type = 'x')->toThrow(Error::class); // readonly — cannot mutate
});

it('DeliveryResult and Recipient are immutable value objects', function () {
    $sent = DeliveryResult::sent('provider-ref');
    expect($sent->status)->toBe(DeliveryStatus::Sent)->and($sent->reference)->toBe('provider-ref')
        ->and($sent->retryable)->toBeFalse();

    $failed = DeliveryResult::failed('boom', retryable: true);
    expect($failed->status)->toBe(DeliveryStatus::Failed)->and($failed->retryable)->toBeTrue()
        ->and($failed->error)->toBe('boom');
    expect(fn () => $sent->retryable = true)->toThrow(Error::class);

    $r = Recipient::forAddress('mail', 'a@b.com');
    expect($r->address)->toBe('a@b.com')->and($r->channel)->toBe('mail')->and($r->user)->toBeNull();
    expect(fn () => $r->address = 'x')->toThrow(Error::class);
});

// ---- API visibility / tiers -------------------------------------------------------------------------------------

it('respects the frozen API tiers: PUBLIC/NEVER-EXTEND are final; INTERNAL are @internal; contracts are interfaces', function () {
    foreach ([NotificationPlatform::class, PendingNotification::class, Recipient::class, OutboundNotification::class, DeliveryResult::class] as $final) {
        expect((new ReflectionClass($final))->isFinal())->toBeTrue("{$final} must be final");
    }
    foreach ([NotificationChannelManager::class, NotificationTypeRegistry::class, NotificationDispatcher::class] as $internal) {
        expect((string) (new ReflectionClass($internal))->getDocComment())->toContain('@internal');
    }
    foreach ([NotificationMessage::class, NotificationChannel::class, NotificationDispatcher::class] as $contract) {
        expect((new ReflectionClass($contract))->isInterface())->toBeTrue("{$contract} must be an interface");
    }
});

it('no public contract crossing to a channel exposes an Eloquent model or a bigint id', function () {
    // The channel boundary is OutboundNotification (uuid string, not a Model / not an int id).
    $params = (new ReflectionMethod(OutboundNotification::class, '__construct'))->getParameters();
    $uuid = $params[0];
    expect($uuid->getName())->toBe('notificationUuid')
        ->and((string) $uuid->getType())->toBe('string'); // a public uuid handle, never an int id

    foreach ((new ReflectionClass(OutboundNotification::class))->getConstructor()->getParameters() as $p) {
        expect((string) $p->getType())->not->toContain('Model')->not->toContain('Eloquent');
    }
});

// ---- Boundary architecture test (mechanism-not-policy, structural) ----------------------------------------------

it('the platform namespace references no product model — structural mechanism-not-policy guard', function () {
    $root = dirname(__DIR__, 2) . '/src/Notifications/Platform';
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
        '/\.php$/'
    );

    $scanned = 0;
    foreach ($files as $file) {
        $src = (string) file_get_contents($file->getPathname());
        $scanned++;
        // No product model, no Eloquent model type — the platform never knows a domain entity.
        expect($src)->not->toContain('App\\Models')
            ->not->toContain('Illuminate\\Database\\Eloquent\\Model');
    }

    expect($scanned)->toBeGreaterThanOrEqual(11); // all platform files were scanned
});

// ---- Type registry (opaque, presentation-only, send-path independent, ships empty) ------------------------------

it('the type registry ships EMPTY — the kernel registers zero built-in types', function () {
    expect(app(NotificationTypeRegistry::class)->all())->toBe([]);
});

it('registerType stores presentation metadata for an opaque, product-owned key', function () {
    NotificationPlatform::registerType('orders.shipped', 'Order shipped', ['inapp', 'mail']);

    $registry = app(NotificationTypeRegistry::class);
    expect($registry->all())->toBe([
        'orders.shipped' => ['label' => 'Order shipped', 'defaults' => ['inapp', 'mail']],
    ]); // an unregistered key simply never appears; the send path does not consult this registry (INV-T4)
});

// ---- Facade wiring ----------------------------------------------------------------------------------------------

it('to() returns a PendingNotification builder', function () {
    expect(NotificationPlatform::to('a@b.com'))->toBeInstanceOf(PendingNotification::class);
});

it('send() and to()->channel()->send() delegate to the bound NotificationDispatcher', function () {
    $spy = new class implements NotificationDispatcher
    {
        /** @var list<array{0: NotificationMessage, 1: mixed, 2: list<string>}> */
        public array $calls = [];

        public function dispatch(NotificationMessage $message, mixed $to, array $channels = []): void
        {
            $this->calls[] = [$message, $to, $channels];
        }
    };
    app()->instance(NotificationDispatcher::class, $spy);
    $message = new FakeMessage('orders.shipped');

    NotificationPlatform::send($message, 'a@b.com');
    NotificationPlatform::to('x@y.com')->channel('inapp', 'mail')->send($message);

    expect($spy->calls)->toHaveCount(2)
        ->and($spy->calls[0])->toBe([$message, 'a@b.com', []])
        ->and($spy->calls[1])->toBe([$message, 'x@y.com', ['inapp', 'mail']]);
});

// ---- Fixtures ---------------------------------------------------------------------------------------------------

/** A minimal channel driver for the delivery-contract + registry tests. */
class FakeChannel implements NotificationChannel
{
    public ?OutboundNotification $received = null;

    public function name(): string
    {
        return 'fake';
    }

    public function deliver(OutboundNotification $outbound): DeliveryResult
    {
        $this->received = $outbound;

        return DeliveryResult::sent('fake-ref');
    }
}

/** A minimal message for the facade-wiring test. */
class FakeMessage implements NotificationMessage
{
    public function __construct(private readonly string $type)
    {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function title(): ?string
    {
        return 'Hi';
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
