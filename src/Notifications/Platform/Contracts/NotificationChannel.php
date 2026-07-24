<?php

namespace Ngos\AdminCore\Notifications\Platform\Contracts;

use Ngos\AdminCore\Notifications\Platform\DeliveryResult;
use Ngos\AdminCore\Notifications\Platform\OutboundNotification;

/**
 * A delivery driver for one medium (in-app, mail, sms, …). This is the platform's extension seam: products and
 * third-party modules implement it and register it through {@see \Ngos\AdminCore\Notifications\Platform\NotificationChannelManager}.
 *
 * A channel receives a read-only {@see OutboundNotification} DTO — never an Eloquent model — and returns a
 * {@see DeliveryResult}. It is dispatcher-independent: given an OutboundNotification it can deliver in isolation.
 *
 * API tier: EXTENSION.
 */
interface NotificationChannel
{
    /**
     * The channel's globally-unique, immutable identifier (lowercase, e.g. `inapp`, `mail`). It is the config /
     * preference / delivery key; renaming it is a breaking change.
     */
    public function name(): string;

    /** Deliver the notification through this medium and report the outcome. */
    public function deliver(OutboundNotification $outbound): DeliveryResult;
}
