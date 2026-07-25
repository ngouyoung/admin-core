# ADR-0010 — Notification Platform

- **Status:** Accepted
- **Date:** 2026-07-25
- **Deciders:** Framework maintainer (ratified via the Notification Platform v2.1 architecture freeze)
- **Related:** ADR-0007 (hybrid identity) · ADR-0011 (channel registry) · ADR-0012 (type seam) · ADR-0013 (API tiers)

## Context

admin-core is becoming a platform shared by many products (LMS, POS, Inventory, CRM, ERP). Notifications were a
thin layer over Laravel Notifications (`Illuminate\Notifications\*`): products called `$user->notify(...)`, storage
was Laravel's `DatabaseNotification` (a uuid-PK table). To own one notification capability across products — and to
add channels, preferences, and a center over time — admin-core needs to own the notification **contracts and store**
while remaining a business-agnostic mechanism.

## Decision

admin-core owns a **Notification Platform**: one business-agnostic mechanism that routes → renders → delivers →
persists → exposes notifications. Products declare *who/what/which type*; the platform owns the rest and never learns
a domain concept.

- **Mechanism, not policy.** No business-domain vocabulary lives in `src/Notifications/Platform/**`; a structural
  architecture test asserts the platform references no `App\Models\*` and no Eloquent model, and the kernel ships
  zero built-in notification types or labels.
- **Own the contracts; reuse the plumbing.** Laravel's Mail, Queue, Broadcast, Events, and Cache are reused
  (wrapped) as infrastructure; `Illuminate\Notifications\*` (the Notification base, `Notifiable` as the product
  entry point, `DatabaseNotification`, the Laravel notifications table) is replaced by the platform contracts and,
  in a later work package, an admin-core-owned store.
- **Send pipeline.** The `NotificationPlatform` facade delegates to a `NotificationDispatcher` that *only
  orchestrates* — recipient resolution, channel routing, delivery recording, and events are small collaborators, so
  the dispatcher never becomes a god-service.
- **Identity (ADR-0007).** Every platform table uses a bigint `id` (PK/FK/joins) + a public `uuid` (the API/URL
  handle); public surfaces expose the uuid, never the bigint id.
- **Rule of Three.** v1 ships only the contracts + registries (this WP) and, next, a single `notifications` table +
  the in-app channel + a thin dispatcher. Preferences, deliveries, templates, retry, localization, digest,
  attachments, multi-tenant, and public egress events are deferred behind reserved seams.

> **Update (v4.0.0).** The full "Rule of Three" v1 has shipped: the admin-core-owned hybrid `notifications` store
> (bigint `id` + public `uuid` + `guard` via `Models\Notification`), the `InAppChannel`, and the thin `Dispatcher`
> are live, and `illuminate/notifications` was removed from `composer.json`. An **optional** `BroadcastChannel`
> (off by default) also shipped for realtime — see [ADR-0014](ADR-0014-notification-broadcast-channel.md). The
> deferred seams (preferences, deliveries, templates, retry, digest, …) remain deferred.

## Consequences

- Products depend on `NotificationPlatform` (and the message/recipient/channel contracts), not Laravel
  Notifications. The public surface is frozen and tiered (ADR-0013).
- admin-core can later apply its own hybrid identity to notifications (leaving Laravel's uuid-PK
  `DatabaseNotification` exemption) and add channels without kernel edits (ADR-0011).
- No business logic can enter the kernel through notifications; the platform is provably domain-blind.

## References

- Notification Platform v2.1 — the canonical specification this ADR ratifies.
- `src/Notifications/Platform/` — the contracts + registries + facade (WP-N1).
- ADR-0007 — the hybrid-identity standard the platform tables adopt.
