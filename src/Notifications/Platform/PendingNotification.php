<?php

namespace Ngos\AdminCore\Notifications\Platform;

use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationDispatcher;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationMessage;

/**
 * The fluent builder returned by {@see NotificationPlatform::to()} — accumulates the target and an optional channel
 * constraint, then hands off to the dispatcher on {@see send()}. Channel selection lives here (not on the message)
 * so a {@see NotificationMessage} stays channel-agnostic and reusable.
 *
 * API tier: PUBLIC · NEVER-EXTEND (final).
 */
final class PendingNotification
{
    /** @var list<string> */
    private array $channels = [];

    /** @param  mixed  $to  the recipient target(s) — a Notifiable model, an email/phone string, an address, or an array of them */
    public function __construct(private readonly mixed $to)
    {
    }

    /** Constrain delivery to the named channels (fluent). Omit to use the message/type default channels. */
    public function channel(string ...$names): self
    {
        $this->channels = array_values(array_unique([...$this->channels, ...$names]));

        return $this;
    }

    /** Dispatch the message to the accumulated target through the (optionally constrained) channels. */
    public function send(NotificationMessage $message): void
    {
        app(NotificationDispatcher::class)->dispatch($message, $this->to, $this->channels);
    }
}
