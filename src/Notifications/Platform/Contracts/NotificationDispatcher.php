<?php

namespace Ngos\AdminCore\Notifications\Platform\Contracts;

/**
 * The delegation seam the {@see \Ngos\AdminCore\Notifications\Platform\NotificationPlatform} facade dispatches
 * through. It is the CONTRACT only — the orchestration (recipient resolution, channel routing, delivery
 * recording) is a later work package; this interface exists so the facade is a real, typed entry point without
 * owning any orchestration logic.
 *
 * API tier: INTERNAL. Engine-only; may change. No product depends on it directly.
 *
 * @internal
 */
interface NotificationDispatcher
{
    /**
     * Dispatch a message to the given target(s), optionally constrained to specific channel names.
     *
     * @param  mixed  $to  the raw recipient target(s) — resolved to recipients by the implementation
     * @param  list<string>  $channels  channel names to use, or [] to use the message/type defaults
     */
    public function dispatch(NotificationMessage $message, mixed $to, array $channels = []): void;
}
