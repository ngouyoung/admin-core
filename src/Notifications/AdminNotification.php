<?php

namespace Ngos\AdminCore\Notifications;

use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationMessage;

/**
 * A ready-made in-app notification for the admin bell — a Notification Platform {@see NotificationMessage} you send
 * without writing a message class per alert type:
 *
 *   use Ngos\AdminCore\Notifications\AdminNotification;
 *   use Ngos\AdminCore\Notifications\Platform\NotificationPlatform;
 *
 *   NotificationPlatform::send(new AdminNotification(
 *       title: 'Order shipped',
 *       message: 'Order #1024 is on its way',
 *       url: route('admin.orders.edit', 1024),
 *       icon: 'bi-truck',
 *   ), $user);
 *
 * It surfaces in <x-admin-core::notifications-bell /> and the /admin/notifications page (the InApp channel stores it;
 * the Notification Center reads it).
 *
 * Realtime/broadcast (live bell + toast) is delivered by a platform broadcast channel, which is not yet shipped — so
 * this message is stored in-app only for now. (Before the platform cutover this class was a Laravel Notification sent
 * with `$user->notify(...)`; it is now a platform message sent with `NotificationPlatform::send(..., $user)`.)
 */
class AdminNotification implements NotificationMessage
{
    /** The opaque, product-owned type key for admin-core's built-in alerts (the platform stores it, never parses it). */
    public const TYPE = 'admin-core.notification';

    /**
     * @param  string       $title    headline shown in the bell and the list
     * @param  string|null  $message  optional supporting line
     * @param  string|null  $url      where the row links to when clicked (e.g. a route())
     * @param  string|null  $icon     a Bootstrap Icons class, e.g. 'bi-truck' (defaults in the view)
     * @param  array<string, scalar|null>  $extra  extra presentation scalars merged into the stored payload (ids, etc.)
     */
    public function __construct(
        public string $title,
        public ?string $message = null,
        public ?string $url = null,
        public ?string $icon = null,
        public array $extra = [],
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function translationKey(): ?string
    {
        return null;
    }

    public function translationParams(): array
    {
        return [];
    }

    public function body(): ?string
    {
        return $this->message;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function icon(): ?string
    {
        return $this->icon;
    }

    /** @return array<string, scalar|null> */
    public function data(): array
    {
        return $this->extra;
    }
}
