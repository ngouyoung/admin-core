# ADR-0008 — Guard precedence for actor resolution (portal-first)

- **Status:** Accepted
- **Date:** 2026-07-24
- **Deciders:** Framework maintainer (ratified)
- **Supersedes:** none · **Superseded by:** none
- **Related:** ADR-0007 (hybrid identity mechanism, sibling identity concern) · AR-1 (`docs/wp/milestone-a/AR-1.md`) · ADR-0009 (dashboard authorization — consumes this decision)

> **On numbering.** This is the first record under `docs/adr/`. ADR-0007 is referenced across the docs as the
> hybrid-identity decision but was never given a physical file; 0008 is the next-free index. admin-core's primary
> governance track is `RFC-XXXX` (RFC-0009 closed, RFC-0010 reserved); ADRs are used for the smaller, standalone
> cross-cutting mechanism decisions (identity → 0007, guard precedence → this). RFC and ADR are distinct
> namespaces — an `ADR-0008` does not clash with the reserved `RFC-0010`.

## Context

admin-core supports multiple authentication guards: the application default guard (`auth.defaults.guard`, usually
`web`) plus any number of configured **portal** guards (`admin-core.permission.guards`). Six cross-cutting
concerns need to answer one question — *"which guard is this request authenticated on, and who is the acting
user?"* — for attribution and scoping: audit causer, media attribution, saved-view scoping, dashboard-layout
persistence, locale-user resolution, and the auto-translate gate.

Before AR-1 that question was answered by a `foreach (guards) { try { auth()->guard($g)->check() } catch {} }`
loop **copy-pasted across all six loci**, and the iteration order had **drifted**: the audit path consulted portal
guards first, while the other five consulted the default guard first. The consequence was an **audit-integrity
defect** — an operator authenticated on *both* the default guard (user A) and a portal guard (user B) at the same
time could have a single request attributed to **two different identities**: the audit trail recorded B while the
dashboard, media, and saved-views acted as A.

AR-1 (merged) introduced `Ngos\AdminCore\Support\ActorResolver` as the single owner of the *(acting user, active
guard)* pair and routed all six loci through it, retiring the copy-pasted walk. That consolidation required one
canonical iteration order. This ADR ratifies which order — the decision AR-1's own Definition of Done named **K1**
and made a freeze prerequisite.

## Decision

The canonical guard iteration order is **portal-first**:

> **Configured portal guards (`admin-core.permission.guards`, in configured order), then the default guard
> (`auth.defaults.guard`), de-duplicated.**

This is the order the security-sensitive audit path (`LogsActivity::resolveCauser`) already used; the other five
loci were brought into line with it (not the reverse). It is implemented by `ActorResolver::guards()` and required
by AR-1 invariant INV-2 / acceptance criterion AC-3.

The precedence is **fixed, not configurable**. A per-application knob would re-open exactly the drift this decision
closes and would let two deployments resolve the same authentication state to different identities.

Rationale: when a request is authenticated on more than one guard, the **most specific** identity — the portal
user — is the correct actor for attribution and scoping. Anchoring on the audit path is deliberate: the audit
trail is the compliance-sensitive surface, and every other locus should agree with it.

## Consequences

**Behavior change (dual active session only).** When a request is authenticated on *both* the default guard
(user A) **and** a configured portal guard (user B), every locus now resolves **(B, portal)**. Previously five of
the six loci resolved (A, default). A **single-active-session** request (only one guard authenticated) is
**unchanged** — the resolver returns the one authenticated user regardless of order. There is no schema or data
migration; per-locus storage keys `(user_id, guard)` are unchanged.

**Positive.** One authentication state yields exactly one identity across the whole framework; the split-identity
audit defect is gone; the undefined-guard hardening (skipping a guard named in admin-core config but absent from
`auth.php`) lives in one place; a new hand-rolled guard walk is caught by an architecture test.

**Neutral / accepted.** The resolved identity is also the *input* to authorization-adjacent surfaces (e.g. the
dashboard's per-widget visibility gate). Whether such gates should authorize by the resolved actor or by the
**panel guard** is a distinct question, deliberately **not** decided here.

## Follow-ups (linked, not decided here)

- **Dashboard authorization vs. panel guard.** In a dual session the dashboard widget gate currently authorizes as
  the resolved (portal) actor, which diverges from the sidebar (panel guard) on the same page. This is an
  *authorization* concern, not the *attribution* concern this ADR settles. It is decided separately in
  **ADR-0009** (dashboard authorization). This is not a data-exposure defect — an operator only ever sees data
  their own accounts are authorized for — but a consistency question.

## References

- `src/Support/ActorResolver.php` — the single resolver (`guards()`, `resolve()`, portal-first order).
- `docs/wp/milestone-a/AR-1.md` — the WP that unified the six loci and named this decision K1.
- CHANGELOG — the AR-1 Unreleased entry (dual-session A3 behavior change).
- ADR-0009 — dashboard authorization (consumes this precedence; records the panel-guard vs resolved-actor call).
