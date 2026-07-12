# Release Manifest — Naming / View Configuration Program

Permanent release index for the RFC-0009 Rev 2 program. This file is an index; per-version detail lives in
`CHANGELOG.md`, `docs/releases/`, and `UPGRADING.md`.

## Program

- **Program name:** Naming / View Configuration (Display Column / Model Naming subsystem)
- **RFC:** RFC-0009 Revision 2 — **CLOSED**
- **Roadmap:** Implementation Roadmap Revision 2 — **COMPLETE**
- **Final audit:** **APPROVED**
- **Prepared:** 2026-07 (tags pending creation; not yet pushed or published)

## Completed backlogs

| # | Title | Landed in |
|---|---|---|
| 1 | Composition Fidelity Fix | v2.86.2 |
| 2 | Implementation Verification | (verification phase) |
| 3 | Enterprise LMS Verification | (verification phase) |
| 4 | Descriptor Accessor | v2.87.0 |
| 5 | Consumer Wiring | v2.87.0 |
| 6 | Resource View Configuration | v2.87.0 |
| 7 | Generator Update | v2.87.0 |
| 8 | Generator Verification | v2.87.0 |
| 9 | Compatibility Flag | v2.87.0 |
| 11 | Documentation | v2.87.0 |
| 12 | v3.0.0 Major Flip | v3.0.0 |

## Deferred backlogs

| # | Title | Disposition |
|---|---|---|
| 10 | (FkOption / write-path / full Explicit NONE propagation) | **Deferred to RFC-0010** |

## Release timeline

| Version | Theme | SemVer | Breaking | Prepared |
|---|---|---|---|---|
| v2.86.1 | Search Portability | PATCH | No | 2026-07 |
| v2.86.2 | Composition Fidelity | PATCH | No | 2026-07 |
| v2.87.0 | Descriptor + View Configuration + Generator + Compatibility Flag + Documentation | MINOR | No | 2026-07 |
| v3.0.0 | Explicit NONE Default | MAJOR | **Yes** | 2026-07 |

Release order (ascending): **v2.86.1 → v2.86.2 → v2.87.0 → v3.0.0**.

## SemVer history

- **v2.86.1** — PATCH: bug fix (search portability); results unchanged under default collations.
- **v2.86.2** — PATCH: bug fix (composition fidelity); behavior unchanged for correct cases.
- **v2.87.0** — MINOR: additive (descriptor, view config, generator emission, opt-in flag default off).
- **v3.0.0** — MAJOR: default behavior change (Explicit NONE for label-less related labels).

## Supported versions

- **v3.0.0** — current / latest.
- **v2.87.0** — previous minor; functionally equivalent to v3.0.0 with `explicit_none` off.
- **v2.86.x** — superseded patch line (folded into v2.87.0).

## Migration timeline

| From → To | Action |
|---|---|
| → v2.86.1 | None. |
| → v2.86.2 | None. |
| → v2.87.0 | None (byte-for-byte). Optional early adoption: `ADMIN_CORE_EXPLICIT_NONE=true`. |
| → v3.0.0 | Breaking for label-less relation display only. Opt out with `ADMIN_CORE_EXPLICIT_NONE=false` (deprecated), or give the related model a real label column. |
| Future major | The `explicit_none` opt-out is removed ("deprecate then remove"). |

## Known future RFCs

- **RFC-0010** (next independent project) — FkOption redesign (option value = RouteKey), write-path, full Explicit
  NONE propagation to runtime search/sort and typed-string consumers, and removal of the `explicit_none` opt-out.
