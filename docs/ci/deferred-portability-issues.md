# Deferred PostgreSQL portability issues (`pgsql-skip`)

Real framework portability bugs surfaced by the TS-1 multi-engine CI matrix. Each is a genuine defect on
PostgreSQL; per TS-1's scope they are **identified, documented, and tracked here — but NOT fixed in TS-1**
(fixing framework portability bugs is out of scope for the CI-matrix work package). Each has its own future
fix WP.

The tests that expose them are tagged `->group('pgsql-skip')`, so the PostgreSQL CI job excludes them
(`--exclude-group=sqlite-only --exclude-group=pgsql-skip`) while **MySQL and SQLite still run them**
(they pass there). When a bug is fixed, remove the `pgsql-skip` tag so the PostgreSQL job covers it again.

> These internal `PORT-n` references should be promoted to tracked issues in the project's issue tracker when
> the branch is pushed. Until then this file is the durable record.

| Ref | Test | Engine | Symptom | Root cause | Status |
|---|---|:--:|---|---|:--:|
| **PORT-1** | `FkDisplayColumnTest` › *falls back to the route key … no SQL error* | PostgreSQL | `SQLSTATE[42883]: function lower(bigint) does not exist` (500) | The display-column route-key fallback and the Select2 remote search wrap the resolved column in `lower(...)`. When the fallback column is the bigint route key (`id`), pgsql has no `lower(bigint)`. MySQL auto-casts bigint→text; SQLite is untyped. | Open — deferred |
| **PORT-2** | `HybridCrudTest` › *does not resolve a hybrid record by its bigint id* | PostgreSQL | `SQLSTATE[22P02]: invalid input syntax for type uuid: "1"` (500, expected 404) | Route-model binding resolves a hybrid record via `where uuid = <key>`. On pgsql the public key column is a native `uuid` type, so a non-uuid key (a bigint like `1`) throws at the DB layer instead of yielding "not found" → 404. MySQL/SQLite store the uuid as text, so a non-matching string simply returns no row → 404. | Open — deferred |

## Suggested fix direction (for the future fix WPs — not part of TS-1)

- **PORT-1:** resolve/search the display column without forcing `lower()` on non-text columns (skip case-folding
  when the column is not a string type), or cast to text portably before `lower()`.
- **PORT-2:** guard route-model binding so a public key that cannot be a valid identifier for the column type
  yields a 404 (model-not-found) rather than propagating a DB type-cast error — e.g. validate/normalise the
  incoming key against the key column's type before querying.

## Notes

- No test is in the `sqlite-only` group today: every other cross-engine failure surfaced during the TS-1
  widening was a genuine test-isolation or test-assumption issue and was **fixed**, not excluded (per the
  approved decision AD-5). `sqlite-only` remains the established deny-list mechanism for any future
  SQLite-only case.
