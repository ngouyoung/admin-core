# ADR-0009 — Dashboard authorization vs. attribution

- **Status:** Accepted — **implemented in WP-B11b** (shipped in this release: the dashboard widget gate now resolves via the panel guard when one is declared, with the portal-first resolver as the null-default fallback)
- **Date:** 2026-07-24
- **Deciders:** Framework maintainer — recorded per the approved Milestone B specification (WP-B11a). The
  panel-guard direction is not a new policy: it aligns the dashboard with admin-core's existing sibling-guard
  authorization pattern (Sidebar, WebController, ApprovalController).
- **Related:** ADR-0008 (guard precedence — this ADR resolves its linked follow-up) · WP-B11b (the implementation of this decision) · `src/Dashboard/Dashboard.php` · `src/Support/Sidebar.php`

## Context

ADR-0008 standardized `ActorResolver` on **portal-first** precedence for *(acting user, active guard)* resolution,
and AR-1 routed the six cross-cutting concerns through it. The dashboard is the one locus that uses the resolved
identity for **two different purposes**:

1. **Attribution / scoping** — `Dashboard::currentUser()` (→ `ActorResolver::actor()`) keys layout persistence on
   `dashboard_layouts (user_id, guard)` and the per-user payload cache; it answers *"whose saved dashboard is
   this?"*.
2. **Authorization** — `Dashboard::visible()` gates each widget with `actingUser()->can($permission)` (→
   `ActorResolver::user()`, post-AR-1); it answers *"may this operator see this widget?"*.

Post-AR-1, **both** use the portal-first resolver. AR-1's adversarial review flagged the second use: in a dual
active session (default + portal), the widget-visibility gate authorizes as the **portal** user, while
every *other* authorization gate in the kernel authorizes by the guard its surface runs under — `Sidebar::visible()`
uses the explicit panel `$guard` (`Sidebar.php:110`), `WebController` uses `$this->guard`, `ApprovalController` uses
`routeGuard($request)`. So on one rendered page the sidebar authorizes user A (web) while the dashboard authorizes
user B (portal). This is **not** a data-exposure defect — an operator only ever sees data their own accounts are
authorized for, and layout/media/saved-view scoping fail closed on a null identity — but it is an authorization
*consistency* defect: the dashboard disagrees with the panel it renders inside.

## Decision

Separate the two concerns by the guard they should each use:

> **Authorization uses the panel guard. Attribution uses the resolved actor.**

- **Dashboard widget-visibility authorization** resolves the acting user by the **guard the dashboard route runs
  under** (the panel guard), exactly as `Sidebar`, `WebController`, and `ApprovalController` already do. It does
  **not** use the global portal-first `ActorResolver` for the `->can()` gate.
- **Dashboard layout persistence, the `(user_id, guard)` storage key, and the per-user cache key** continue to use
  the **resolved actor** (`ActorResolver::actor()`, portal-first) — consistent with the other attribution surfaces
  (audit causer, media attribution, saved-view scoping).

The governing principle for the framework: **authorization is answered by the guard the surface runs under;
attribution/scoping is answered by the single canonical resolved acting identity (ADR-0008).** `ActorResolver`
remains the one owner of *attribution*; it is not the authority for a surface's *authorization* guard.

## Consequences

- **WP-B11b** implemented this split and **shipped it in this release** (dashboard authorization resolves by the
  panel/route guard when a guard is declared; attribution stays on `ActorResolver`, portal-first). The ADR itself
  authors no code — it is the ratified decision B11b was built to.
- **Single active session** is unchanged (the panel guard and the resolved actor are the same user).
- **Dual active session:** the web dashboard authorizes its widgets as the **web** (panel) user — matching the
  sidebar on the same page — while its saved layout stays attributed to the resolved actor. The sidebar/dashboard
  divergence is removed.
- **Mechanism, not policy.** Both "the panel guard" and "the resolved actor" are framework-owned identity
  mechanisms; no domain knowledge is introduced, and no new authorization *policy* is minted — a widget's required
  permission is unchanged, only *which guard's user* the existing `->can()` consults.

## References

- `src/Dashboard/Dashboard.php` — `visible()`/`actingUser()` (authorization) and `currentUser()` (attribution).
- `src/Support/Sidebar.php:110`, `src/Http/Controllers/WebController.php`, `src/Http/Controllers/ApprovalController.php` — the panel-guard authorization pattern this aligns the dashboard with.
- ADR-0008 — the attribution precedence this ADR consumes and complements.
- AR-1's adversarial review — the dashboard/sidebar authorization divergence this decision resolves (recorded in the Milestone-A close-out review, not in the AR-1 WP spec).
