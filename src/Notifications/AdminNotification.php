<?php

namespace Ngos\AdminCore\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * A ready-made database notification for the admin bell, so you can fire an in-app alert
 * without writing a Notification class per message type:
 *
 *   use Ngos\AdminCore\Notifications\AdminNotification;
 *
 *   $user->notify(new AdminNotification(
 *       title: 'Order shipped',
 *       message: 'Order #1024 is on its way',
 *       url: route('admin.orders.edit', 1024),
 *       icon: 'bi-truck',
 *   ));
 *
 * It surfaces in <x-admin-core::notifications-bell /> and the /admin/notifications page.
 * When config('admin-core.notifications.realtime') is on (or you pass broadcast: true) it
 * ALSO broadcasts, so the bell updates live + a toast pops on arrival (needs a broadcaster +
 * Echo + a queue worker — see the README). Need mail/SMS/etc.? Write your own Notification —
 * `toArray()` just has to return `title` / `message` / `url` / `icon`.
 */
class AdminNotification extends Notification
{
    /**
     * @param  string       $title    headline shown in the bell and the list
     * @param  string|null  $message  optional supporting line
     * @param  string|null  $url      where the row links to when clicked (e.g. a route())
     * @param  string|null  $icon     a Bootstrap Icons class, e.g. 'bi-truck' (defaults in the view)
     * @param  array<string, mixed>  $extra  extra keys merged into the stored payload (ids, etc.)
     * @param  bool|null  $broadcast  also broadcast (live bell)? null = follow config('admin-core.notifications.realtime')
     */
    public function __construct(
        public string $title,
        public ?string $message = null,
        public ?string $url = null,
        public ?string $icon = null,
        public array $extra = [],
        public ?bool $broadcast = null,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($this->broadcast ?? (bool) config('admin-core.notifications.realtime', false)) {
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    /** The live (broadcast) payload — same shape as the stored one, for the bell listener. */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_merge([
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'icon' => $this->icon,
        ], $this->extra);
    }
}
