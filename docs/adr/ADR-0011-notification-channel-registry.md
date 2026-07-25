# ADR-0011 — Notification Channel Registry

- **Status:** Accepted
- **Date:** 2026-07-25
- **Deciders:** Framework maintainer (Notification Platform v2.1 freeze)
- **Related:** ADR-0010 (platform) · ADR-0013 (API tiers)

## Context

The platform must deliver through many media (in-app, mail, broadcast, sms, telegram, push, …) and let a host or a
third-party module add a channel **without editing the kernel** (Open/Closed). admin-core already ships a proven
driver registry — `Translation\TranslationManager` — with a config-seeded driver map, a runtime `extend()`, and
throw-on-unknown.

## Decision

Channels are drivers behind a minimal, frozen contract and are resolved through an **open registry** that reuses the
`TranslationManager` pattern.

- **Contract (EXTENSION tier):** `NotificationChannel { name(): string; deliver(OutboundNotification): DeliveryResult; }`.
  A channel receives a read-only `OutboundNotification` DTO — never an Eloquent model — and is dispatcher-independent.
- **Registry:** `NotificationChannelManager` holds a `name → class-string|Closure` map **seeded from
  `config('admin-core.notifications.channels')`**, exposes **`extend(string $name, Closure|string $driver): self`**
  (registered from a provider's `boot()`), and **`make()` throws on an unknown name**. It is **bound as a singleton**
  so runtime `extend()` calls persist for the request; the dispatcher resolves every driver **through** it. The
  manager does exactly this and nothing more (no name-format validation, no send logic).
- **Channel identity rules (contract, enforced by review/ADR — not by the manager):** globally unique, immutable
  (renaming = breaking/MAJOR), lowercase `^[a-z][a-z0-9]*$` (third parties may namespace as `vendor.channel`).
  First-party reserved names: `inapp`, `mail`, `broadcast`, `sms`, `telegram`, `push`, `slack`, `webhook`. A
  preference/route referencing a removed channel degrades gracefully (the router skips it, never throws at send).

> **Update (v4.0.0).** Two first-party channels now ship, wired through this registry via
> `config('admin-core.notifications.channels')`: `inapp` (`InAppChannel`) and the optional `broadcast`
> (`BroadcastChannel`, off by default). Both are registered by name exactly as the contract describes; the manager
> was unchanged. See [ADR-0014](ADR-0014-notification-broadcast-channel.md) for the broadcast channel.

## Consequences

- Third-party modules install channels via `extend()` + config; the kernel is closed for modification, open for
  extension.
- The `OutboundNotification`/`DeliveryResult` DTOs keep persistence out of the channel contract, so the store can
  evolve behind the dispatcher.

## References

- `src/Notifications/Platform/NotificationChannelManager.php`, `Contracts/NotificationChannel.php`,
  `OutboundNotification.php`, `DeliveryResult.php`.
- `src/Notifications/Platform/InAppChannel.php`, `BroadcastChannel.php`, `Broadcast/` (the realtime channel + its
  publisher seam) — the first-party channel realizations (v4.0.0).
- `src/Translation/TranslationManager.php` — the driver-registry pattern reused.
