<?php

namespace Ngos\AdminCore\Notifications\Platform;

use Ngos\AdminCore\Notifications\Platform\Broadcast\BroadcastEnvelope;
use Ngos\AdminCore\Notifications\Platform\Broadcast\ChannelNameResolver;
use Ngos\AdminCore\Notifications\Platform\Broadcast\Contracts\BroadcastPublisher;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationChannel;
use Throwable;

/**
 * The optional realtime channel — a peer of {@see InAppChannel}, invoked by the dispatcher with no special-casing. It
 * PUBLISHES the notification the platform already built (the same public uuid the InApp row is persisted under) to the
 * recipient's private channel, and nothing more. It does not create, persist, resolve recipients, authorize, or hold
 * business logic — the recipient + rendered content are already inside the {@see OutboundNotification}.
 *
 * TOTAL FUNCTION — it must NEVER throw. Any failure (a throwing transport SDK, a missing/misconfigured driver, a
 * DI error, a bad envelope) is caught and returned as {@see DeliveryResult::failed()}. This is load-bearing: the
 * frozen {@see Dispatcher} has no per-channel try/catch, so an exception escaping here would abort delivery for every
 * remaining recipient (including their InApp persistence). Best-effort by construction — the store is the source of
 * truth; a dropped broadcast is recovered by the frontend's next sync.
 *
 * API tier: INTERNAL (a channel driver; registered via config/extend under the name `broadcast`).
 *
 * @internal
 */
final class BroadcastChannel implements NotificationChannel
{
    public function __construct(
        private readonly BroadcastPublisher $publisher,
        private readonly ChannelNameResolver $channels,
    ) {
    }

    public function name(): string
    {
        return 'broadcast';
    }

    public function deliver(OutboundNotification $outbound): DeliveryResult
    {
        try {
            $channel = $this->channels->for($outbound->recipient);
            if ($channel === null) {
                // A bare-address recipient (email/phone, no user) has no private realtime channel — nothing to do.
                return DeliveryResult::failed('broadcast requires a user recipient');
            }

            $this->publisher->publish(new BroadcastEnvelope(
                $channel,
                (string) config('admin-core.notifications.broadcast.event', 'notification.created'),
                $this->payload($outbound),
            ));

            return DeliveryResult::sent();
        } catch (Throwable $e) {
            // Never propagate — quarantine the failure so InApp (and the rest of the dispatch loop) are untouched.
            return DeliveryResult::failed($e->getMessage(), retryable: true);
        }
    }

    /**
     * The wire payload: the public uuid + opaque type + the already-rendered presentation content. Presentation-only
     * scalars — never a model, a Recipient, or a business object.
     *
     * @return array<string, scalar|null>
     */
    private function payload(OutboundNotification $outbound): array
    {
        return array_merge(
            ['uuid' => $outbound->notificationUuid, 'type' => $outbound->type],
            $outbound->content,
        );
    }
}
