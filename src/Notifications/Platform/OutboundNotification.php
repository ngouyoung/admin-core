<?php

namespace Ngos\AdminCore\Notifications\Platform;

/**
 * The read-only DTO a {@see Contracts\NotificationChannel} receives — the boundary that keeps the platform's
 * persistence models out of the channel contract. It carries the public notification `uuid` (never a bigint id),
 * the opaque type key, the resolved {@see Recipient} (user + guard for internal channels, channel + address for
 * external ones), and the per-channel rendered `content`. No platform persistence model ever crosses here.
 *
 * API tier: PUBLIC · NEVER-EXTEND (final; read-only — construct once, read, never subclass).
 */
final class OutboundNotification
{
    /**
     * @param  string  $notificationUuid  the notification's PUBLIC handle (never the internal bigint id)
     * @param  string  $type  the opaque, product-owned type key
     * @param  Recipient  $recipient  the resolved recipient — an internal channel reads user + guard, an external one reads channel + address
     * @param  array<string, scalar|null>  $content  the already-rendered, presentation-only payload for this channel
     */
    public function __construct(
        public readonly string $notificationUuid,
        public readonly string $type,
        public readonly Recipient $recipient,
        public readonly array $content,
    ) {
    }
}
