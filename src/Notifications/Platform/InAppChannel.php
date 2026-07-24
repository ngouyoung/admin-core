<?php

namespace Ngos\AdminCore\Notifications\Platform;

use Illuminate\Database\Eloquent\Model;
use Ngos\AdminCore\Models\Notification;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationChannel;

/**
 * The in-app delivery channel: materialises a notification as a {@see Notification} row keyed by the recipient's
 * notifiable morph + guard (read from the {@see OutboundNotification}'s {@see Recipient}). It writes the row and
 * returns a {@see DeliveryResult} — nothing else.
 *
 * API tier: INTERNAL (a channel driver registered as `inapp`).
 *
 * @internal
 */
final class InAppChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'inapp';
    }

    public function deliver(OutboundNotification $outbound): DeliveryResult
    {
        $user = $outbound->recipient->user;

        // In-app is a morph-keyed inbox; a bare-address recipient has no in-app row.
        if ($user === null) {
            return DeliveryResult::failed('The in-app channel requires a user recipient.');
        }

        $morphType = $user instanceof Model ? $user->getMorphClass() : $user::class;
        $morphId = $user instanceof Model ? $user->getKey() : $user->getAuthIdentifier();

        Notification::query()->create([
            'uuid' => $outbound->notificationUuid,
            'notifiable_type' => $morphType,
            'notifiable_id' => $morphId,
            'guard' => $outbound->recipient->guard,
            'type' => $outbound->type,
            'data' => $outbound->content,
        ]);

        return DeliveryResult::sent();
    }
}
