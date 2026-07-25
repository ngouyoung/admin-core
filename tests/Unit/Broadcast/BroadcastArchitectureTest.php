<?php

use Ngos\AdminCore\Notifications\Platform\ChannelRouter;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationChannel;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationDispatcher;
use Ngos\AdminCore\Notifications\Platform\Dispatcher;
use Ngos\AdminCore\Notifications\Platform\InAppChannel;
use Ngos\AdminCore\Notifications\Platform\NotificationChannelManager;
use Ngos\AdminCore\Notifications\Platform\OutboundNotification;

/*
 * WP-N6A — architecture guards: broadcast must be a leaf extension, the kernel must stay transport-agnostic, and the
 * frozen contracts must keep their exact shape.
 */

function kernelSource(string $class): string
{
    return (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
}

it('the kernel (Dispatcher / ChannelManager / ChannelRouter) is transport-agnostic — no Reverb/Pusher/Ably/Echo/broadcast', function () {
    foreach ([Dispatcher::class, NotificationChannelManager::class, ChannelRouter::class] as $kernel) {
        $src = strtolower(kernelSource($kernel));
        foreach (['reverb', 'pusher', 'ably', 'echo', 'broadcast', 'websocket'] as $transport) {
            expect($src)->not->toContain($transport, "kernel {$kernel} must not mention {$transport}");
        }
    }
});

it('no transport class leaks into the kernel (no Broadcast/Broadcasting imports)', function () {
    foreach ([Dispatcher::class, NotificationChannelManager::class, ChannelRouter::class] as $kernel) {
        $src = kernelSource($kernel);
        expect($src)->not->toContain('Platform\\Broadcast')
            ->and($src)->not->toContain('Illuminate\\Contracts\\Broadcasting')
            ->and($src)->not->toContain('Illuminate\\Broadcasting');
    }
});

it('BroadcastChannel is the ONLY broadcast-aware channel — InAppChannel has zero broadcast/publish awareness', function () {
    $src = strtolower(kernelSource(InAppChannel::class));
    foreach (['broadcast', 'publish', 'reverb', 'echo', 'envelope'] as $term) {
        expect($src)->not->toContain($term);
    }
});

it('ChannelRouter reads a generic default_channels list and names no channel in code', function () {
    // routing membership lives in config; the router is name-blind
    config(['admin-core.notifications.default_channels' => ['inapp', 'broadcast']]);
    expect((new ChannelRouter)->route([]))->toBe(['inapp', 'broadcast']);

    // a caller constraint still wins verbatim, and the legacy single default still works as a fallback
    config(['admin-core.notifications.default_channels' => null]);
    config(['admin-core.notifications.default_channel' => 'inapp']);
    expect((new ChannelRouter)->route([]))->toBe(['inapp'])
        ->and((new ChannelRouter)->route(['broadcast']))->toBe(['broadcast']);
});

it('the frozen platform contracts keep their exact shape', function () {
    // NotificationChannel: deliver(OutboundNotification): DeliveryResult
    $deliver = new ReflectionMethod(NotificationChannel::class, 'deliver');
    expect((string) $deliver->getParameters()[0]->getType())->toBe(OutboundNotification::class)
        ->and((string) $deliver->getReturnType())->toContain('DeliveryResult');

    // OutboundNotification: the four frozen constructor params, in order
    $params = (new ReflectionClass(OutboundNotification::class))->getConstructor()->getParameters();
    expect(array_map(fn ($p) => $p->getName(), $params))->toBe(['notificationUuid', 'type', 'recipient', 'content']);

    // the dispatcher still implements the frozen contract and is final (kernel, not extended)
    $dispatcher = new ReflectionClass(Dispatcher::class);
    expect($dispatcher->implementsInterface(NotificationDispatcher::class))->toBeTrue()
        ->and($dispatcher->isFinal())->toBeTrue();
});
