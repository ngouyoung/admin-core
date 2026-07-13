# Contributing to `ngos/admin-core`

Thanks for your interest in improving `ngos/admin-core`. This document covers reporting bugs, coding standards,
running the tests, and opening pull requests.

## Reporting bugs

Open a [GitHub issue](https://github.com/ngouyoung/admin-core/issues) with:

- the package version (tag) and your PHP / Laravel versions,
- a clear description of what you expected versus what happened,
- the smallest reproduction you can manage (a failing test, a minimal resource spec, or steps), and
- the full error / stack trace where relevant.

For **security** vulnerabilities, do **not** open a public issue — follow [`SECURITY.md`](SECURITY.md) instead.

## Requirements

- PHP `^8.3`
- Laravel `^13` (the package depends on `illuminate/*` `^13.0`)
- Node 22 for the front-end tests

## Running the tests

Install dependencies and run the suites the CI runs:

```bash
composer install
composer test        # Pest (PHPUnit) feature + unit suite
composer analyse     # PHPStan (Larastan) — level 5, must stay clean

npm ci
npm test             # Vitest (front-end JS)
```

The database layer runs on SQLite in-memory by default. The cross-database suite (`search-portability`) can be run
against a real PostgreSQL/MySQL by exporting `DB_CONNECTION` + `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`.

## Coding standards

- Follow the existing style of the surrounding code (Laravel conventions, PSR-12 spacing and imports).
- Keep the framework a **mechanism, not a policy**: no business/domain logic in the package core. See
  [`ARCHITECTURE.md`](ARCHITECTURE.md).
- Every change must keep `composer test` and `composer analyse` green. New behavior needs tests; a bug fix needs a
  regression test that fails before the fix.
- Match the comment density and naming of the code you touch; prefer small, focused changes.

## Pull requests

1. Fork the repository and create a topic branch from `main`.
2. Make your change with tests, and update `CHANGELOG.md` (and `UPGRADING.md` if behavior or upgrade steps change).
3. Ensure `composer test`, `composer analyse`, and `npm test` all pass locally.
4. Open a PR with a clear description of the problem and the approach. Keep unrelated changes out of the PR.
5. Use [Conventional Commits](https://www.conventionalcommits.org/) for messages (e.g. `fix:`, `feat:`, `docs:`,
   `feat!:` for a breaking change).

By contributing, you agree that your contributions are licensed under the project's [MIT License](LICENSE).
