<?php

namespace Ngos\AdminCore\Notifications\Platform\Broadcast;

use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\DB;
use Ngos\AdminCore\Notifications\Platform\Broadcast\Contracts\BroadcastPublisher;
use Throwable;

/**
 * Publishes an envelope over a Pusher-protocol transport (Laravel Reverb — and Pusher/Ably-compatible, via the named
 * broadcasting connection). It is a leaf: the platform kernel never references it; only the container binding, chosen
 * from `notifications.broadcast.driver`, does.
 *
 * Ordering invariant — publish ONLY after the surrounding DB transaction commits, so a rollback can never leave a
 * broadcast for a notification row that never existed. `DB::afterCommit` runs the callback immediately when no
 * transaction is open. (This is the SYNC strategy; the queue strategy is a drop-in — a publisher that
 * `dispatch(job)->afterCommit()` — which moves the transport round trip off the request path without BroadcastChannel
 * knowing. Queue policy lives in the publisher, per the design.)
 *
 * TOTAL across the DEFERRED path — the transport round-trip is wrapped in its own try/catch. When a transaction is
 * open the callback fires later, at commit time, INSIDE Laravel's unguarded afterCommit-callback loop and OUTSIDE
 * {@see \Ngos\AdminCore\Notifications\Platform\BroadcastChannel}'s try/catch (which has already returned). A transient
 * transport failure (Reverb/Pusher unreachable) or a misconfigured connection must NOT escape into the host request or
 * abort sibling afterCommit callbacks — broadcast is best-effort and the store is the source of truth — so it is
 * swallowed and reported here. This is the load-bearing half of the never-throw guarantee for queued/transactional
 * sends; BroadcastChannel cannot catch a throw that fires after it returns.
 *
 * The private-channel prefix (`private-`) is a transport convention, so it lives here, not in the transport-neutral
 * envelope or the channel-name resolver (whose logical name the authorization side matches).
 *
 * API tier: INTERNAL.
 *
 * @internal
 */
final class ReverbPublisher implements BroadcastPublisher
{
    public function __construct(
        private readonly BroadcastFactory $broadcast,
        private readonly ?string $connection = null,
    ) {
    }

    public function publish(BroadcastEnvelope $envelope): void
    {
        DB::afterCommit(function () use ($envelope): void {
            try {
                $this->broadcast->connection($this->connection)->broadcast(
                    ['private-' . $envelope->channel],
                    $envelope->event,
                    $envelope->payload,
                );
            } catch (Throwable $e) {
                // Deferred: this runs at commit time, outside BroadcastChannel's guard and inside Laravel's
                // unguarded afterCommit loop. Best-effort/at-most-once — never let a transport failure escape into
                // the host request or abort sibling callbacks. Report (observable) but do not propagate.
                report($e);
            }
        });
    }
}
