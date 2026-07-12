# Final Release Report — RFC-0009 Rev 2 (Naming / View Configuration)

_Prepared: 2026-07. Status: implementation complete, audit APPROVED, release prepared (tags pending)._

## Implementation summary

The program began with a single dogfooded defect — a self-referential relation resolving a `uuid` route key and
crashing on PostgreSQL — and closed it correctly by building a formalized Display Column / Model Naming subsystem:

1. **Search portability** (v2.86.1) — made `Support\Search` SQL portable across all supported databases, unblocking
   cross-database verification.
2. **Composition fidelity** (v2.86.2) — display column resolves from the immutable model type, never a mutable/
   aliased instance; removed the memo-poisoning route-key fallback for self-joins.
3. **Descriptor + view configuration + generator + flag** (v2.87.0) — a structured `ac_display_descriptor()`; the
   immutable `RelationDisplayColumn`, `SearchColumnSet`, `SortColumnSet` value objects; runtime consumers and the
   generator routed through them; and the opt-in `explicit_none` compatibility flag (default off). All byte-for-byte.
4. **Explicit NONE default flip** (v3.0.0) — the flag defaults on: a label-less related model renders empty instead
   of leaking its route key, with a deprecated opt-out for migration.

## Architecture summary

Mechanism, not policy: the kernel gained only column-name machinery and never learns what a model represents. Value
objects are `final` + `readonly` (immutable, stateless), matching the `Support\Money` precedent — no new service,
resolver, registry, metadata subsystem, or extension point beyond the two RFC-sanctioned resource overrides.
Composition fidelity (resolve type facts from the immutable class) is the load-bearing invariant and is intact.

## Testing summary

- **978 tests / 3,806 assertions passing; PHPStan L5 clean.**
- Program coverage: `DisplayColumnFidelityTest`, `SearchPortabilityTest`, `DisplayDescriptorTest`,
  `DisplayLabelWiringTest`, `ResourceViewConfigTest`, `ExplicitNoneFlagTest`, plus generator emission / determinism /
  `php -l` locks in `GeneratorTest` and `FieldSetTest`.
- **Cross-database matrix** (PostgreSQL / MySQL / SQLite) runs the naming + search family — the regressions that
  started this program can no longer hide behind SQLite.

## Release summary

Four releases in order — **v2.86.1 (PATCH) → v2.86.2 (PATCH) → v2.87.0 (MINOR) → v3.0.0 (MAJOR)**. SemVer is correct
and stated per version. CHANGELOG lists all four in descending order and is internally consistent. Release notes and
per-version detail are in `docs/releases/`.

## Documentation summary

- `CHANGELOG.md` — canonical per-version list (v3.0.0 → v2.86.1).
- `UPGRADING.md` — `→ v2.87.0` (no action) and `→ v3.0.0` (breaking, opt-out) migration guides.
- `README.md` — display-column section reflects the v3.0.0 default, the descriptor, and the flag.
- `docs/releases/*.md` — one professional release document per version.
- `RELEASE_MANIFEST.md`, `PROJECT_STATUS.md`, this report — permanent index / status / summary.
- Verified consistent: no contradictory wording across README, CHANGELOG, UPGRADING, and `docs/releases/`.

## Migration summary

v2.86.1 / v2.86.2 / v2.87.0 require no action (byte-for-byte by default). v3.0.0 is breaking for label-less relation
display only; reversible via the deprecated `explicit_none=false` opt-out or by giving the related model a real label
column. A future major removes the opt-out.

## Deferred work

Deferred to RFC-0010 (none implemented, none leaked): FkOption redesign (option value = RouteKey), write-path,
runtime search/sort NONE-disabling in generated code, Explicit NONE for typed-string consumers
(`select`/`export` via `ac_display_column(): string`), and removal of the `explicit_none` opt-out.

## Known limitations

- Explicit NONE (v3.0.0) applies to the **display** of label-less related models only; runtime search/sort and the
  typed-string `select`/`export` label surfaces still resolve a real fallback column (safe, non-crashing) and are
  RFC-0010 scope.
- The `explicit_none` opt-out is a temporary, deprecated migration bridge, not a permanent configuration surface.

## Future roadmap

- **RFC-0010** — the next independent project: FkOption, write-path, full Explicit NONE propagation, and opt-out
  removal.

## Final approval

Final audit verdict: **APPROVED.** RFC-0009 Rev 2 is fully realized and closed; Roadmap Rev 2 is complete; governance
was enforced. The release is prepared and ready for tag creation and publication upon explicit approval. No commits,
tags, pushes, or GitHub Releases have been created by this preparation.
