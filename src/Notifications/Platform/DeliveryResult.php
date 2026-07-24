<?php

namespace Ngos\AdminCore\Notifications\Platform;

/**
 * The immutable outcome a {@see Contracts\NotificationChannel} returns from a delivery: the status, an optional
 * provider reference, whether a failure is worth retrying, and an optional error message.
 *
 * API tier: PUBLIC · NEVER-EXTEND (final value object — construct via {@see sent()}/{@see failed()}, read, never subclass).
 */
final class DeliveryResult
{
    private function __construct(
        public readonly DeliveryStatus $status,
        public readonly ?string $reference,
        public readonly bool $retryable,
        public readonly ?string $error,
    ) {
    }

    /** A successful delivery, with an optional provider reference (message id, etc.). */
    public static function sent(?string $reference = null): self
    {
        return new self(DeliveryStatus::Sent, $reference, false, null);
    }

    /** A failed delivery; `retryable` marks a transient failure worth retrying (a later work package acts on it). */
    public static function failed(string $error, bool $retryable = false): self
    {
        return new self(DeliveryStatus::Failed, null, $retryable, $error);
    }
}
