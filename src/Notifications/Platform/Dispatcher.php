<?php

namespace Ngos\AdminCore\Notifications\Platform;

use Illuminate\Support\Str;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationDispatcher;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationMessage;

/**
 * The dispatcher — orchestration ONLY. It resolves recipients ({@see RecipientResolver}), resolves channels
 * ({@see ChannelRouter}), and invokes the channel drivers ({@see NotificationChannelManager}). It makes no
 * persistence, business, retry, or scheduling decision — the channel persists; the dispatcher never does.
 *
 * API tier: INTERNAL. Implements the frozen {@see NotificationDispatcher} contract.
 *
 * @internal
 */
final class Dispatcher implements NotificationDispatcher
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly ChannelRouter $router,
        private readonly NotificationChannelManager $channels,
    ) {
    }

    public function dispatch(NotificationMessage $message, mixed $to, array $channels = []): void
    {
        $channelNames = $this->router->route($channels);
        $content = $this->content($message);

        foreach ($this->recipients->resolve($to) as $recipient) {
            $uuid = (string) Str::uuid7();

            foreach ($channelNames as $name) {
                $this->channels->make($name)->deliver(
                    new OutboundNotification($uuid, $message->type(), $recipient, $content),
                );
            }
        }
    }

    /**
     * The message's own presentation fields, assembled into the outbound content — the message renders itself
     * (v2.1); there is no TemplateRenderer here (deferred). Mechanical field-mapping, not rendering logic.
     *
     * @return array<string, scalar|null>
     */
    private function content(NotificationMessage $message): array
    {
        return array_merge(
            array_filter([
                'title' => $message->title(),
                'translationKey' => $message->translationKey(),
                'body' => $message->body(),
                'url' => $message->url(),
                'icon' => $message->icon(),
            ], fn ($value) => $value !== null),
            $message->data(),
        );
    }
}
