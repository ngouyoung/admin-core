<?php

namespace Ngos\AdminCore\Notifications\Platform;

/**
 * The read-only DTO a {@see Contracts\NotificationChannel} receives — the boundary that keeps persistence out of
 * the channel contract. It carries the public notification `uuid` (never a bigint id), the opaque type key, the
 * channel-specific target `address`, and the per-channel rendered `content`. No Eloquent model ever crosses here.
 *
 * API tier: PUBLIC · NEVER-EXTEND (final; read-only — construct once, read, never subclass).
 */
final class OutboundNotification
{
    /**
     * @param  string  $notificationUuid  the notification's PUBLIC handle (never the internal bigint id)
     * @param  string  $type  the opaque, product-owned type key
     * @param  string  $address  the channel-specific target (an email, a phone number, a recipient handle, …)
     * @param  array<string, scalar|null>  $content  the already-rendered, presentation-only payload for this channel
     */
    public function __construct(
        public readonly string $notificationUuid,
        public readonly string $type,
        public readonly string $address,
        public readonly array $content,
    ) {
    }
}
