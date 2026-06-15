<?php

namespace Ngos\AdminCore\Notifications;

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
 * Need more (mail, broadcast, queued)? Write your own Notification — `toArray()` just has to
 * return `title` / `message` / `url` / `icon`.
 */
class AdminNotification extends Notification
{
    /**
     * @param  string       $title    headline shown in the bell and the list
     * @param  string|null  $message  optional supporting line
     * @param  string|null  $url      where the row links to when clicked (e.g. a route())
     * @param  string|null  $icon     a Bootstrap Icons class, e.g. 'bi-truck' (defaults in the view)
     * @param  array<string, mixed>  $extra  extra keys merged into the stored payload (ids, etc.)
     */
    public function __construct(
        public string $title,
        public ?string $message = null,
        public ?string $url = null,
        public ?string $icon = null,
        public array $extra = [],
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
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
