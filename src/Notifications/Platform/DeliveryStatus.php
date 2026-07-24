<?php

namespace Ngos\AdminCore\Notifications\Platform;

/**
 * The synchronous outcome a channel reports for a single delivery. Asynchronous states (queued, delivered by a
 * provider callback) belong to later work packages that introduce queued channels and the delivery record.
 *
 * API tier: PUBLIC · NEVER-EXTEND (a backed enum; read it, do not wrap it).
 */
enum DeliveryStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
