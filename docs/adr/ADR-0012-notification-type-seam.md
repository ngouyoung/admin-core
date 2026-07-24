# ADR-0012 — Notification Type seam

- **Status:** Accepted
- **Date:** 2026-07-25
- **Deciders:** Framework maintainer (Notification Platform v2.1 freeze)
- **Related:** ADR-0010 (platform) · ADR-0013 (API tiers)

## Context

A notification "type" (`orders.shipped`, etc.) is the natural key for preference filtering and Center grouping. The
danger is that rendering a preference UI forces the kernel to enumerate product concepts — a mechanism-not-policy
leak. The framework already ships an open runtime registry for product-declared things: `Dashboard::register()`.

## Decision

Notification types are **opaque, product-owned strings**, registered as **presentation metadata only**.

- **INV-T1** — a type is an opaque string; the platform never parses, interprets, or branches on its value.
- **INV-T2** — the kernel ships **zero** built-in types and **zero** labels; all types + labels are product-registered.
- **INV-T3** — the type registry (`NotificationTypeRegistry`, reached via `NotificationPlatform::registerType(key,
  label, defaults)`) holds **presentation metadata only** (label, default channels) — never consulted on the send path.
- **INV-T4** — **send-path independence:** a message with an *unregistered* type still routes, delivers, and stores;
  registration only affects the preference/Center UI.
- **INV-T5** — the key is stored verbatim (≤191 chars); dot-namespacing (`product.event`) is a convention the
  platform does not enforce or parse.

Registration reuses the `Dashboard::register()` precedent — a product-facing, `boot()`-time runtime registry, bound
as a singleton (app-scoped, reset per app boot). Labels/defaults are product-supplied data passing through the
kernel, identical in kind to a message title; the kernel authors none.

## Consequences

- The future preference UI can render toggles/labels from the registry without the kernel ever knowing what a type
  *means* — mechanism, not policy.
- Because send never consults the registry, dispatch can never be coupled to a type catalogue.

## References

- `src/Notifications/Platform/NotificationTypeRegistry.php`, `NotificationPlatform::registerType()`.
- `src/Dashboard/Dashboard.php` (`register()`) — the runtime-registry pattern reused.
