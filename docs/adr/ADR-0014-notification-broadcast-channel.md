# ADR-0014 — Realtime Broadcast Notification Channel

- **Status:** Accepted
- **Date:** 2026-07-26
- **Deciders:** Framework maintainer (Notification Platform V1, v4.0.0)
- **Related:** ADR-0010 (platform) · ADR-0011 (channel registry) · ADR-0013 (API tiers)

## Context

The platform cutover (ADR-0010) removed Laravel's notification runtime and, with it, the old broadcast path
(`ADMIN_CORE_REALTIME` + Laravel-notification broadcasts). In-app delivery persists to the store, but there is no
realtime push. We want realtime back — **without** re-coupling the kernel to a websocket transport, and without
touching the frozen contracts. The channel registry (ADR-0011) already lets a channel be added by name; the open
question is *how* broadcast should be modeled so it stays a leaf.

## Decision

Ship realtime as an **optional `broadcast` channel — a peer `NotificationChannel`, not a side effect of
`InAppChannel`.** The `Dispatcher` invokes it exactly like any other channel; InApp and Broadcast execute
independently with no special-casing. Key rules:

- **Off by default.** The channel is registered in config, but binds a `NullPublisher` unless
  `notifications.broadcast.enabled` is set — so routing to it is always a safe no-op. Routing membership
  (`notifications.default_channels`) and transport enablement are separate concerns; the kernel names no transport
  in code.
- **Total function — it must never throw.** The `Dispatcher` has no per-channel `try/catch`, so a throw would abort
  delivery for every remaining recipient (including their InApp persistence). `BroadcastChannel::deliver()` catches
  everything and returns `DeliveryResult::failed(retryable)`; a `NullPublisher` fallback guarantees
  `make('broadcast')` itself never throws. The real (`ReverbPublisher`) transport call is *also* total across the
  deferred, post-commit path.
- **Publish after commit; best-effort / at-most-once.** The persisted store is the **source of truth**; broadcast is
  a nudge. `ReverbPublisher` publishes via `DB::afterCommit`, so a rollback never emits a broadcast for a row that
  never existed, and a dropped message is recovered by the frontend's next store re-sync.
- **One naming algorithm for publish and auth.** A single `ChannelNameResolver` derives the recipient's private
  channel name from **morph identity only** (`getMorphClass()` + `getKey()`; guard is structurally null). The same
  function feeds the publisher and the channel authorizer, so the two can never diverge. The mapping is **injective**
  — two distinct identities can never collapse to one channel (a security invariant). Authorization is **owner-only**.
- **Transport behind a seam.** `BroadcastChannel` publishes an immutable `BroadcastEnvelope` through a
  `BroadcastPublisher` (`NullPublisher` | `ReverbPublisher`). The delivery strategy (sync vs queued) lives in the
  publisher; swapping it is a binding change, invisible to the channel. The kernel imports no
  `Illuminate\Broadcasting`/`Broadcasting` type (a structural architecture test enforces this).

**Rejected alternative:** broadcast as an event emitted by `InAppChannel` after it persists (a decorator/side
effect). It couples the two channels, makes "broadcast without persist" and "persist without broadcast" special
cases, and hides a transport concern inside the store channel. Peer-channel modeling keeps each channel a single
responsibility and lets the router decide membership generically.

## Consequences

- Realtime is available again as an opt-in, with **zero behavior change** for existing installs (default `['inapp']`,
  `NullPublisher`).
- Adding another realtime transport (Pusher, Ably, SSE, a queued publisher) is a `BroadcastPublisher` implementation
  + a binding — no channel or kernel edit, per ADR-0011.
- The frozen public contracts (ADR-0013) were untouched; the `Dispatcher` stayed transport-agnostic.

## References

- `src/Notifications/Platform/BroadcastChannel.php` — the peer channel (total function).
- `src/Notifications/Platform/Broadcast/` — `BroadcastEnvelope`, `Contracts/BroadcastPublisher`, `NullPublisher`,
  `ReverbPublisher`, `ChannelNameResolver`, `ChannelAuthorizer`.
- `config/admin-core.php` — `notifications.{default_channels, broadcast.*}`.
- `stubs/frontend/resources/js/{echo,realtime}.js.stub` — the fresh, opt-in realtime frontend.
- `tests/Unit/Broadcast/`, `tests/Feature/BroadcastChannelTest.php`, `tests/js/realtime.test.js`.
