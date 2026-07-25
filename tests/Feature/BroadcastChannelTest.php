<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\Notification;
use Ngos\AdminCore\Notifications\AdminNotification;
use Ngos\AdminCore\Notifications\Platform\Broadcast\BroadcastEnvelope;
use Ngos\AdminCore\Notifications\Platform\Broadcast\ChannelNameResolver;
use Ngos\AdminCore\Notifications\Platform\Broadcast\Contracts\BroadcastPublisher;
use Ngos\AdminCore\Notifications\Platform\Broadcast\ReverbPublisher;
use Ngos\AdminCore\Notifications\Platform\BroadcastChannel;
use Ngos\AdminCore\Notifications\Platform\NotificationPlatform;
use Ngos\AdminCore\Notifications\Platform\OutboundNotification;
use Ngos\AdminCore\Notifications\Platform\Recipient;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/*
 * WP-N6A — BroadcastChannel: unit (total function) + integration through the frozen platform pipeline (InApp +
 * Broadcast as independent peers, failure isolation, publish-after-commit).
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

// ---- BroadcastChannel — unit (total function; never persists) ---------------------------------------------------

it('publishes the OutboundNotification (uuid + type + content) to the recipient private channel and returns sent', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $spy = new SpyBroadcastPublisher;
    $channel = new BroadcastChannel($spy, new ChannelNameResolver);

    $result = $channel->deliver(new OutboundNotification('uuid-1', 'orders.shipped', Recipient::forUser($user), ['title' => 'Hi', 'body' => 'B']));

    expect($result->status->value)->toBe('sent')
        ->and($spy->envelopes)->toHaveCount(1)
        ->and($spy->envelopes[0]->channel)->toBe((new ChannelNameResolver)->forUser($user))
        ->and($spy->envelopes[0]->payload)->toBe(['uuid' => 'uuid-1', 'type' => 'orders.shipped', 'title' => 'Hi', 'body' => 'B']);
    expect(Notification::count())->toBe(0); // the channel NEVER persists — that is InApp's job
});

it('NEVER throws on a transport failure — returns a retryable failed result instead', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $channel = new BroadcastChannel(new ThrowingBroadcastPublisher, new ChannelNameResolver);

    $result = $channel->deliver(new OutboundNotification('u', 't', Recipient::forUser($user), []));

    expect($result->status->value)->toBe('failed')
        ->and($result->retryable)->toBeTrue()
        ->and($result->error)->toContain('transport down');
});

it('fails (does not publish) for a bare-address recipient — no morph identity, no private channel', function () {
    $spy = new SpyBroadcastPublisher;
    $result = (new BroadcastChannel($spy, new ChannelNameResolver))
        ->deliver(new OutboundNotification('u', 't', Recipient::forAddress('mail', 'a@b.com'), []));

    expect($result->status->value)->toBe('failed')
        ->and($result->retryable)->toBeFalse()
        ->and($spy->envelopes)->toBeEmpty();
});

// ---- Integration — InApp + Broadcast as independent peers through the platform -----------------------------------

it('InApp and Broadcast both execute when routed together (independent peers)', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $spy = new SpyBroadcastPublisher;
    app()->instance(BroadcastPublisher::class, $spy);

    NotificationPlatform::to($user)->channel('inapp', 'broadcast')->send(new BroadcastTestMessage('orders.shipped', 'Shipped'));

    expect(Notification::count())->toBe(1)                              // InApp persisted the store row
        ->and($spy->envelopes)->toHaveCount(1)                         // Broadcast published
        ->and($spy->envelopes[0]->payload['type'])->toBe('orders.shipped')
        ->and($spy->envelopes[0]->payload['uuid'])->toBe(Notification::first()->uuid); // same public uuid as the persisted row
});

it('a broadcast transport failure does NOT prevent InApp persistence — even when broadcast is routed FIRST', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    app()->instance(BroadcastPublisher::class, new ThrowingBroadcastPublisher);

    // broadcast first, then inapp — proves a throwing channel can't abort the dispatch loop / the InApp write
    NotificationPlatform::to($user)->channel('broadcast', 'inapp')->send(new BroadcastTestMessage('t', 'Hi'));

    expect(Notification::count())->toBe(1); // InApp row still written despite broadcast throwing
});

it('publishes only AFTER the surrounding transaction commits (end-to-end)', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $recorder = new BroadcastRecorder;
    app()->instance(BroadcastPublisher::class, new ReverbPublisher(new FakeBroadcastFactory(new RecordingBroadcaster($recorder))));

    DB::beginTransaction();
    NotificationPlatform::to($user)->channel('broadcast')->send(new BroadcastTestMessage('t', 'Hi'));
    expect($recorder->calls)->toBeEmpty();       // nothing on the wire while the transaction is open
    DB::commit();

    expect($recorder->calls)->toHaveCount(1);    // published once the transaction committed
});

// ---- fixtures ---------------------------------------------------------------------------------------------------

class SpyBroadcastPublisher implements BroadcastPublisher
{
    /** @var list<BroadcastEnvelope> */
    public array $envelopes = [];

    public function publish(BroadcastEnvelope $envelope): void
    {
        $this->envelopes[] = $envelope;
    }
}

class ThrowingBroadcastPublisher implements BroadcastPublisher
{
    public function publish(BroadcastEnvelope $envelope): void
    {
        throw new RuntimeException('transport down');
    }
}

class BroadcastRecorder
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];
}

class RecordingBroadcaster implements Broadcaster
{
    public function __construct(private BroadcastRecorder $rec)
    {
    }

    public function auth($request)
    {
    }

    public function validAuthenticationResponse($request, $result)
    {
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $this->rec->calls[] = ['channels' => $channels, 'event' => $event, 'payload' => $payload];
    }
}

class FakeBroadcastFactory implements BroadcastFactory
{
    public function __construct(private Broadcaster $b)
    {
    }

    public function connection($name = null)
    {
        return $this->b;
    }
}

class BroadcastTestMessage implements \Ngos\AdminCore\Notifications\Platform\Contracts\NotificationMessage
{
    public function __construct(private string $type, private string $title)
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
