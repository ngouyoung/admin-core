# ADR-0013 — Notification Platform Public API tiers

- **Status:** Accepted
- **Date:** 2026-07-25
- **Deciders:** Framework maintainer (Notification Platform v2.1 freeze)
- **Related:** ADR-0010 (platform) · ADR-0011 (channel registry) · ADR-0012 (type seam)

## Context

The platform is a long-lived, cross-product contract. Every type must have exactly one API-stability tier so
products know what they may depend on, implement, or extend, and so admin-core knows what it may change.

## Decision

Four tiers, each type in exactly one:

- **PUBLIC** — products call/construct it: `NotificationPlatform` (facade, `final`); `NotificationMessage` (interface
  a product constructs/implements); `NotificationPlatform::registerType()`; the accepted recipient inputs (Model |
  email | phone | address — resolved to a `Recipient` value object, no host interface).
- **EXTENSION** — products/modules implement/register against it: `NotificationChannel` (driver interface);
  `NotificationChannelManager::extend()` + the channels config.
- **NEVER-EXTEND** — final value objects/events; read or construct, never subclass: `PendingNotification`,
  `Recipient`, `OutboundNotification`, `DeliveryResult`, `DeliveryStatus`, and (deferred) the lifecycle events.
- **INTERNAL** — engine-only, may change without notice: `NotificationDispatcher` (the delegation contract),
  `NotificationChannelManager`, `NotificationTypeRegistry` (the classes; their extend/register methods are the
  public surface), and later the dispatcher collaborators, the Center, and the `Notification` model.

**Boundary rules:** no INTERNAL type appears in a PUBLIC signature; no PUBLIC/NEVER-EXTEND payload carries an
Eloquent model or a bigint id (channels receive `OutboundNotification`; events carry uuids/scalars). Compatibility:
PUBLIC/EXTENSION signatures are additive-only; a break is a MAJOR release.

Tiers are encoded in code: `final` on PUBLIC-final/NEVER-EXTEND classes, `@internal` docblocks on INTERNAL classes,
interfaces for the contracts — and asserted by a contract test.

> **Update (v4.0.0).** The engine pieces this ADR assigned to INTERNAL have shipped and slot in exactly as stated:
> the dispatcher collaborators, the Notification Center, `Models\Notification`, and the broadcast channel's internals
> (`Broadcast/`) are all `@internal`. The PUBLIC-tier contracts remained frozen through the store, in-app, and
> broadcast work.

## Consequences

- Products have a stable, minimal surface; admin-core can evolve the engine (dispatcher, store, Center) freely.
- The frozen DTO/event boundary guarantees persistence never leaks to a public consumer.

## References

- `src/Notifications/Platform/**` — the tiered types.
- `tests/Unit/NotificationPlatformContractTest.php` — the tier/immutability/boundary assertions.
