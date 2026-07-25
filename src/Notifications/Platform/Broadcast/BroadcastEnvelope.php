<?php

namespace Ngos\AdminCore\Notifications\Platform\Broadcast;

/**
 * The read-only transport DTO a {@see Contracts\BroadcastPublisher} receives — the boundary that keeps the platform's
 * {@see \Ngos\AdminCore\Notifications\Platform\OutboundNotification} (and its Recipient) out of transport code. A
 * publisher sees only what a wire needs: the private channel name, the event name, and an already-rendered scalar
 * payload. No platform DTO, model, or Recipient ever crosses here.
 *
 * API tier: EXTENSION-support · read-only value object (build once, read, never mutate).
 */
final class BroadcastEnvelope
{
    /**
     * @param  string  $channel  the private channel name the payload is published on (from {@see ChannelNameResolver})
     * @param  string  $event  the event name the frontend listens for
     * @param  array<string, scalar|null>  $payload  presentation-only scalars (uuid, type, and the rendered content)
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $event,
        public readonly array $payload,
    ) {
    }
}
