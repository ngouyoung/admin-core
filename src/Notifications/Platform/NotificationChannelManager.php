<?php

namespace Ngos\AdminCore\Notifications\Platform;

use Closure;
use InvalidArgumentException;
use Ngos\AdminCore\Notifications\Platform\Contracts\NotificationChannel;

/**
 * The open channel registry — the platform's Open/Closed extension seam for delivery drivers. Mirrors
 * {@see \Ngos\AdminCore\Translation\TranslationManager}: a `name → class-string|Closure` map seeded from
 * `config('admin-core.notifications.channels')`, a runtime {@see extend()} so a host or third-party module can
 * register a channel from a service provider's boot() without editing the kernel, and {@see make()} that throws on
 * an unknown name. Bound as a singleton so `extend()` registrations persist for the request.
 *
 * API tier: INTERNAL (the class). Its `extend()` + the config map form the EXTENSION surface.
 *
 * @internal
 */
final class NotificationChannelManager
{
    /** @var array<string, class-string<NotificationChannel>|Closure(): NotificationChannel> */
    private array $drivers = [];

    public function __construct()
    {
        /** @var array<string, class-string<NotificationChannel>|Closure(): NotificationChannel> $configured */
        $configured = (array) config('admin-core.notifications.channels', []);
        $this->drivers = $configured;
    }

    /** Register or override a channel driver by name (a class-string or a factory closure). */
    public function extend(string $name, Closure|string $driver): self
    {
        $this->drivers[$name] = $driver;

        return $this;
    }

    /** Resolve a channel driver by name. Throws when the name is not registered. */
    public function make(string $name): NotificationChannel
    {
        if (! isset($this->drivers[$name])) {
            throw new InvalidArgumentException("Unknown admin-core notification channel [{$name}].");
        }

        $driver = $this->drivers[$name];
        $channel = $driver instanceof Closure ? $driver() : app($driver);

        if (! $channel instanceof NotificationChannel) {
            throw new InvalidArgumentException("Notification channel [{$name}] must implement NotificationChannel.");
        }

        return $channel;
    }
}
