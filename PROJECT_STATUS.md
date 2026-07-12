# Project Status

A point-in-time status index for the `ngos/admin-core` naming/view-configuration line of work. Complements — does
not replace — `RELEASE_MANIFEST.md`, `CHANGELOG.md`, and `docs/releases/`.

_Last updated: 2026-07 (release preparation; tags pending)._

## Completed projects

- **RFC-0009 Rev 2 — Naming / View Configuration** — **COMPLETE / CLOSED.** Delivered across v2.86.1, v2.86.2,
  v2.87.0, and v3.0.0. Final audit: **APPROVED**.

## Current active project

- **None on RFC-0009.** RFC-0009 Rev 2 is closed; no further implementation work remains on it.
- **Next up:** RFC-0010 (see below) becomes the next independent project when opened.

## Closed RFCs

- **RFC-0009 Revision 2** — Display Column / Model Naming subsystem. Fully realized; closed.

## Deferred RFCs

- **RFC-0010** — not yet opened. Carries the work intentionally deferred from RFC-0009 Rev 2 (Backlog #10): FkOption
  redesign, write-path, full Explicit NONE propagation to search/sort and typed-string consumers, and removal of the
  `explicit_none` opt-out. No RFC-0010 work has been implemented in this program.

## Architecture status

- **FROZEN.** No architecture changes were made by this program. The subsystem is mechanism-only (column-name
  machinery; zero business logic), immutable/stateless value objects following the `Support\Money` idiom, with no new
  service, resolver, registry, metadata subsystem, or extension point beyond the two RFC-sanctioned override points.

## Governance status

- **COMPLETE / ENFORCED.** One-item-per-phase scope discipline held across all backlogs; frozen documents
  (Constitution, Architecture, ADRs, RFC, Roadmap, Governance) untouched; adversarial verification findings were
  resolved rather than waived.

## Release status

- **Prepared, pending tag + publish.** Four releases are staged in the working tree (v2.86.1 → v2.86.2 → v2.87.0 →
  v3.0.0). Annotated tags are planned but **not created**; nothing is pushed or published. See "Remaining manual
  steps" in the release preparation summary.
- **Quality gates:** 978 tests / 3,806 assertions passing; PHPStan L5 clean; cross-database CI (PostgreSQL / MySQL /
  SQLite).

## Next planned RFC

- **RFC-0010** — the next independent project (scope listed under Deferred RFCs).
