<?php

namespace Ngos\AdminCore\Notifications\Platform\Broadcast\Contracts;

use Ngos\AdminCore\Notifications\Platform\Broadcast\BroadcastEnvelope;

/**
 * The transport abstraction {@see \Ngos\AdminCore\Notifications\Platform\BroadcastChannel} publishes through — the
 * seam that keeps the channel (and the whole platform kernel) ignorant of Reverb / Pusher / Ably / Echo. A publisher
 * is handed a wire-ready {@see BroadcastEnvelope} and pushes it to its transport.
 *
 * This interface is also where the DELIVERY STRATEGY lives (sync vs queued) and the ordering invariant is enforced:
 * a real publisher must publish ONLY after the surrounding database transaction commits (e.g. via DB::afterCommit or
 * a queued job dispatched afterCommit) — {@see \Ngos\AdminCore\Notifications\Platform\Broadcast\ReverbPublisher}. The
 * BroadcastChannel never learns which strategy is used; swapping sync for queued is a publisher-binding change only.
 *
 * API tier: EXTENSION. Add a transport (Pusher, Ably, SSE, a custom gateway) by implementing this and binding it via
 * `notifications.broadcast.driver`; BroadcastChannel is untouched.
 */
interface BroadcastPublisher
{
    /** Publish the envelope to this transport, after the current DB transaction commits. Must not throw. */
    public function publish(BroadcastEnvelope $envelope): void;
}
