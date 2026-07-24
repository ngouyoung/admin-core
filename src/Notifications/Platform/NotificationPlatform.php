<?php

namespace Ngos\AdminCore\Notifications\Platform;

use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationDispatcher;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationMessage;

/**
 * The single product-facing entry point to the Notification Platform. Products never touch Laravel Notifications
 * directly — they go through this facade: send a message to a target, build a fluent send, or register a
 * notification type's presentation metadata. The facade owns no logic; it delegates dispatch to the
 * {@see NotificationDispatcher} and type registration to the {@see NotificationTypeRegistry}.
 *
 * API tier: PUBLIC · final.
 */
final class NotificationPlatform
{
    /**
     * Send a message to the given target(s).
     *
     * @param  mixed  $to  a Notifiable model, an email/phone string, an address, or an array of them
     */
    public static function send(NotificationMessage $message, mixed $to): void
    {
        app(NotificationDispatcher::class)->dispatch($message, $to);
    }

    /**
     * Begin a fluent send to the given target(s).
     *
     * @param  mixed  $to  a Notifiable model, an email/phone string, an address, or an array of them
     */
    public static function to(mixed $to): PendingNotification
    {
        return new PendingNotification($to);
    }

    /**
     * Register a notification type's presentation metadata (label + default channels) for the preference UI. The
     * key is opaque and product-owned; this NEVER affects the send path.
     *
     * @param  list<string>  $defaults  the type's default channel names
     */
    public static function registerType(string $key, string $label, array $defaults = []): void
    {
        app(NotificationTypeRegistry::class)->register($key, $label, $defaults);
    }
}
