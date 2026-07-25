<?php

namespace Ngos\AdminCore\Notifications\Platform;

/**
 * Decides which channels a notification goes to: the channels a caller constrained via
 * {@see PendingNotification::channel()} if any, otherwise the configured default channel. Initial implementation
 * only — NO preferences, NO scheduling, NO retry (later work packages). It never consults the type registry, so
 * routing stays independent of the presentation-metadata catalogue (INV-T4).
 *
 * API tier: INTERNAL (a dispatcher collaborator).
 *
 * @internal
 */
final class ChannelRouter
{
    /**
     * @param  list<string>  $requested  channel names the caller constrained to, or [] for the default
     * @return list<string>  the channel names to deliver through
     */
    public function route(array $requested): array
    {
        if ($requested !== []) {
            return array_values(array_unique($requested));
        }

        // The configured default channel SET, read verbatim as opaque names — the router stays transport-blind and
        // names no specific channel driver in code; those live only in config. Falls back to the legacy single
        // `default_channel`, then to `inapp`, so existing configs are unaffected.
        $defaults = config('admin-core.notifications.default_channels');
        if (is_array($defaults) && $defaults !== []) {
            return array_values(array_unique(array_map('strval', $defaults)));
        }

        return [(string) config('admin-core.notifications.default_channel', 'inapp')];
    }
}
