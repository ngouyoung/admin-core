# Releasing `ngos/admin-core`

This package is the **single source of truth**. It lives at `github.com/ngouyoung/admin-core`
and is consumed by the host app (`laravel-9-admin-lte-3`) as a **git submodule** at
`packages/ngos/admin-core/` (wired into the host via a composer `path` repository).

There is **no copy step** — you edit, commit, tag, and push from this one tree. Packagist
auto-syncs from the new tag.

## Release steps

```bash
cd packages/ngos/admin-core

# 1. Make sure you're on a branch (submodules can check out detached)
git checkout main && git pull

# 2. Make your changes, then add a CHANGELOG.md entry under a new "## vX.Y.Z" heading

# 3. Run the same gate CI runs — both must be green BEFORE tagging
composer analyse      # Larastan, PHPStan level 5
composer test         # Pest suite

# 4. Commit, tag, push
git commit -am "Describe the change"
git tag vX.Y.Z
git push origin main --tags
```

Packagist updates within a minute or two. Verify:

```bash
curl -s https://repo.packagist.org/p2/ngos/admin-core.json | grep -o '"version":"v[0-9.]*"' | head -1
```

## Record the new version in the host app (optional)

The host pins an exact submodule commit. To move it to the release you just cut:

```bash
cd ../../..                         # host repo root
git add packages/ngos/admin-core
git commit -m "Bump admin-core to vX.Y.Z"
```

## Versioning

- Tag-driven (SemVer). **Do not** add a `version` field to `composer.json` — Packagist reads tags.
- Patch (`x.y.Z`): fixes, tooling. Minor (`x.Y.0`): new features, back-compatible.
  Major (`X.0.0`): breaking changes — narrowing a signature, removing a config key, etc.
- Breaking changes need an `UPGRADING.md` note (e.g. "re-run `admin-core:install --access --force`").

## Fresh clones

Because the package is a submodule, clone the host with submodules:

```bash
git clone --recursive <host-repo-url>
# or, in an existing clone:
git submodule update --init
```

## CI

Every push / PR to this repo runs (`.github/workflows/tests.yml`):

- **Test** — Pest on PHP 8.3 + 8.4 (`composer test`)
- **Static analysis** — Larastan / PHPStan level 5 (`composer analyse`)

A red check blocks the release — fix it before tagging. The PHPStan baseline
(`phpstan-baseline.neon`) only grandfathers framework-dynamic false positives;
real signature/LSP breaks are non-ignorable and will fail CI.
