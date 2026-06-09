# Changelog

All notable changes to `ngos/admin-core` are documented here.

## Unreleased

- **Audit trail**: a `LogsActivity` trait + `ActivityLog` model (in the package) record
  create/update/delete with the actor and changed attributes. `admin-core:make --audit`
  (or `generator.audit` config) adds it to a resource; `--access` ships a read-only Activity Log
  viewer; `admin-core:install` publishes the `activity_logs` migration.

## v1.0.0

Initial release.

### Core
- Config-driven `CrudController` + `CrudService` + `Route::crud()` route macro (permission-gated).
- Accepts `int|string` keys (integer and UUID resources coexist).

### Generator (`admin-core:make`)
- `--fields` DSL: string, text, integer, decimal, boolean, date, datetime, email,
  `enum:a|b|c`, `foreign`, `image`, `file`, `belongsToMany`, with `?` (nullable) / `^` (unique).
- Generates migration, model (+relations), form requests, controller, service (with upload/sync
  logic), Blade views, factory, seeder, policy, and a read-only show view.
- `--uuid` (UUID keys) and `--soft-deletes` (trash/restore) flags.
- Auto-registers the resource in the sidebar.
- Every list ships export (CSV), bulk delete, and per-column filters.

### Install (`admin-core:install`)
- Minimal zero-build starter, or `--access` for the full AdminLTE 4 (Vite) kit: login,
  Users/Roles/Permissions/Group-Permissions (with the nestable tree + checktree), profile/account,
  settings module, and a stat-card dashboard.
- Idempotent, sentinel-wrapped edits; `admin-core:version` / `uninstall` (`--purge`) / `reinstall`.

### Tested
- Pest + Orchestra Testbench suite (FieldSet, Route::crud, CrudController).
