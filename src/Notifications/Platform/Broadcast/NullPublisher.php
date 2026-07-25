<?php

namespace Ngos\AdminCore\Notifications\Platform\Broadcast;

use Ngos\AdminCore\Notifications\Platform\Broadcast\Contracts\BroadcastPublisher;

/**
 * The no-op publisher — the always-safe fallback. It is bound whenever broadcast is disabled OR the configured driver
 * is unknown/misconfigured, so {@see \Ngos\AdminCore\Notifications\Platform\NotificationChannelManager}::make('broadcast')
 * and {@see \Ngos\AdminCore\Notifications\Platform\BroadcastChannel} can ALWAYS resolve a publisher and never throw —
 * guaranteeing a misconfigured transport can never abort the dispatch loop or block InApp delivery.
 *
 * API tier: INTERNAL.
 *
 * @internal
 */
final class NullPublisher implements BroadcastPublisher
{
    public function publish(BroadcastEnvelope $envelope): void
    {
        // Intentionally nothing — broadcast disabled / no transport configured.
    }
}
