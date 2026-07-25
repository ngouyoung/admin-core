<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Notifications\Platform\Broadcast\BroadcastEnvelope;
use Ngos\AdminCore\Notifications\Platform\Broadcast\ChannelAuthorizer;
use Ngos\AdminCore\Notifications\Platform\Broadcast\ChannelNameResolver;
use Ngos\AdminCore\Notifications\Platform\Broadcast\NullPublisher;
use Ngos\AdminCore\Notifications\Platform\Broadcast\ReverbPublisher;
use Ngos\AdminCore\Notifications\Platform\Recipient;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/*
 * WP-N6A — the broadcast supporting parts (envelope, resolver, authorizer, null + reverb publishers). Pure units,
 * no platform pipeline involved.
 */

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->timestamps();
    });
});

afterEach(fn () => Schema::dropIfExists('users'));

// ---- BroadcastEnvelope (immutable) ------------------------------------------------------------------------------

it('BroadcastEnvelope is an immutable read-only DTO', function () {
    $e = new BroadcastEnvelope('admin-core.notifications.X.1', 'notification.created', ['uuid' => 'u']);

    expect($e->channel)->toBe('admin-core.notifications.X.1')
        ->and($e->event)->toBe('notification.created')
        ->and($e->payload)->toBe(['uuid' => 'u']);
    expect(fn () => $e->channel = 'x')->toThrow(Error::class); // readonly
});

// ---- ChannelNameResolver (single algorithm, morph identity, no guard) -------------------------------------------

it('derives the channel from morph identity, identical for the recipient and the connecting user', function () {
    $user = NotifiableUser::create(['name' => 'A']);
    $resolver = new ChannelNameResolver;

    $fromRecipient = $resolver->for(Recipient::forUser($user));
    $fromUser = $resolver->forUser($user);

    expect($fromRecipient)->toBe($fromUser)                                   // publish side == auth side (byte-identical)
        ->and($fromRecipient)->toBe($resolver->channel($user->getMorphClass(), (string) $user->getKey()))
        ->and($fromRecipient)->toStartWith('admin-core.notifications.');
});

it('returns null for a bare-address recipient (no morph identity, no private channel)', function () {
    expect((new ChannelNameResolver)->for(Recipient::forAddress('mail', 'a@b.com')))->toBeNull();
});

it('encodes a namespaced morph class into one dot-free channel segment', function () {
    $resolver = new ChannelNameResolver;
    expect($resolver->segment('App\\Models\\User'))->toBe('App_5cModels_5cUser') // '\' (0x5c) -> _5c, dot-free
        ->and($resolver->channel('App\\Models\\User', '5'))->toBe('admin-core.notifications.App_5cModels_5cUser.5');
});

it('segment() is INJECTIVE — distinct identities that a lossy sanitiser would collapse map to DIFFERENT channels', function () {
    $resolver = new ChannelNameResolver;
    // A replace-with-dash sanitiser mapped all of these onto the same 'a-b-x-com' → a cross-user stream collision.
    $keys = ['a-b@x.com', 'a.b@x.com', 'a_b@x.com', 'a b@x.com'];
    $segments = array_map(fn ($k) => $resolver->segment($k), $keys);
    expect($segments)->toBe(array_unique($segments))                 // no two collide
        ->and($resolver->segment('a-b'))->not->toBe($resolver->segment('a.b'))
        ->and($resolver->segment('a_b'))->not->toBe($resolver->segment('a-b')) // a literal '_' is escaped, so unambiguous
        ->and($resolver->segment('plain5'))->toBe('plain5');         // alphanumerics pass through untouched
});

// ---- ChannelAuthorizer (owner-only, same resolver) --------------------------------------------------------------

it('authorizes only the channel owner, by the same morph identity the publisher uses', function () {
    $resolver = new ChannelNameResolver;
    $auth = new ChannelAuthorizer($resolver);
    $me = NotifiableUser::create(['name' => 'Me']);
    $other = NotifiableUser::create(['name' => 'Other']);

    $type = $resolver->segment($me->getMorphClass());
    $id = $resolver->segment((string) $me->getKey());

    expect($auth->authorize($me, $type, $id))->toBeTrue()                    // owner
        ->and($auth->authorize($other, $type, $id))->toBeFalse()             // a different user cannot subscribe
        ->and($auth->authorize($me, $type, '999'))->toBeFalse()              // not the owner's id
        ->and($auth->authorize($me, 'App-Models-Wrong', $id))->toBeFalse();  // not the owner's type
});

// ---- NullPublisher (safe no-op) ---------------------------------------------------------------------------------

it('NullPublisher publishes nothing and never throws', function () {
    expect(fn () => (new NullPublisher)->publish(new BroadcastEnvelope('c', 'e', [])))->not->toThrow(Throwable::class);
});

// ---- ReverbPublisher (publishes AFTER commit, to the private channel) -------------------------------------------

it('ReverbPublisher publishes ONLY after the database transaction commits, on the private channel', function () {
    $recorder = new class
    {
        /** @var list<array{channels: array<int,string>, event: string, payload: array<string,mixed>}> */
        public array $calls = [];
    };
    $broadcaster = new class($recorder) implements Broadcaster
    {
        public function __construct(private object $rec)
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
            $this->rec->calls[] = ['channels' => $channels, 'event' => (string) $event, 'payload' => $payload];
        }
    };
    $factory = new class($broadcaster) implements BroadcastFactory
    {
        public function __construct(private Broadcaster $b)
        {
        }

        public function connection($name = null)
        {
            return $this->b;
        }
    };

    $publisher = new ReverbPublisher($factory);
    $envelope = new BroadcastEnvelope('admin-core.notifications.X.1', 'notification.created', ['uuid' => 'u']);

    DB::beginTransaction();
    $publisher->publish($envelope);
    expect($recorder->calls)->toBeEmpty();   // NOT published while the transaction is open (before commit)
    DB::commit();

    expect($recorder->calls)->toHaveCount(1)
        ->and($recorder->calls[0]['channels'])->toBe(['private-admin-core.notifications.X.1']) // private- prefix (transport)
        ->and($recorder->calls[0]['event'])->toBe('notification.created')
        ->and($recorder->calls[0]['payload'])->toBe(['uuid' => 'u']);
});

it('ReverbPublisher SWALLOWS a transport failure that fires at commit — it never escapes the transaction', function () {
    // The deferred round-trip runs at commit time, OUTSIDE BroadcastChannel's guard and inside Laravel's unguarded
    // afterCommit loop, so the publisher itself must be total there — else a transient Reverb outage 500s the host
    // request and aborts sibling afterCommit callbacks. Broadcast is best-effort; the store is the source of truth.
    $throwing = new class implements Broadcaster
    {
        public function auth($request)
        {
        }

        public function validAuthenticationResponse($request, $result)
        {
        }

        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new RuntimeException('reverb unreachable');
        }
    };
    $factory = new class($throwing) implements BroadcastFactory
    {
        public function __construct(private Broadcaster $b)
        {
        }

        public function connection($name = null)
        {
            return $this->b;
        }
    };

    $publisher = new ReverbPublisher($factory);

    DB::beginTransaction();
    $publisher->publish(new BroadcastEnvelope('admin-core.notifications.X.1', 'notification.created', []));
    // Commit runs the deferred (throwing) callback — it must be swallowed, so commit returns normally.
    expect(fn () => DB::commit())->not->toThrow(Throwable::class);
});
