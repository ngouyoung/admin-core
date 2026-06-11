# Upgrading

`ngos/admin-core` follows semver-ish tags. Most upgrades are `composer update` + a
re-build of the front-end. The notes below cover the releases that need a manual step.
See `CHANGELOG.md` for the full per-version list.

After any release that touches the theme or JS, rebuild the front-end:

```bash
npm install && npm run build
```

---

## → v1.9.0 — Segmented filter tabs

No breaking change. Newly generated resources with an **enum** field get
`<x-admin-core::filter-tabs>` on their index automatically. To add tabs to an existing
list, drop the component above the table:

```blade
<x-admin-core::filter-tabs table="#posts_table" :column="2"
    :tabs="['' => 'All', 'draft' => 'Draft', 'published' => 'Published']" />
```

`column` is the DataTable column index (the leading checkbox is `0`); each array key is
the value searched on that column (empty = clear), the value is the label.

## → v1.8.0 — Page-header component

Generated and `--access` list pages now use `<x-admin-core::page-header>` instead of a
`@section('breadcrumb')` block. Existing hand-written pages keep working; to adopt the new
header, replace the breadcrumb section with:

```blade
<x-admin-core::page-header title="Posts" description="Manage your posts.">
    <x-slot:actions>
        <a href="{{ route('admin.posts.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Add Post
        </a>
    </x-slot:actions>
</x-admin-core::page-header>
```

Resource-specific row actions now go **inside** the kebab (⋯) menu rather than as separate
buttons — pass them to `actions()`:

```php
->addColumn('actions', fn ($row) => $this->actions($row, 'post', [
    ['label' => 'Publish', 'url' => route(...), 'icon' => 'bi bi-send', 'can' => 'edit-post'],
]))
```

## → v1.5.0 — Custom theme (AdminLTE removed)

The front-end no longer depends on `admin-lte`. Re-publish the themed assets and rebuild:

```bash
php artisan admin-core:install --access --force
npm install && npm run build
```

Custom row links in your own views must use the model's route key, not the raw id:
`route('admin.x.edit', $row->getRouteKey())`.

## → v1.3.0 — Hybrid keys (bigint PK + public uuid)

`--uuid` / `generator.uuid` now generate a **bigint `id` primary key plus a unique public
`uuid` column** used in URLs (instead of a uuid primary key). Foreign/pivot keys are always
`foreignId` (bigint).

**Breaking:** resources previously generated with a uuid primary key should be regenerated
(or keep their own migrations). Models use the `HasPublicUuid` trait, which sets
`getRouteKeyName() => 'uuid'`; `CrudService` resolves every action by the route key, so all
edit/show/update/delete/bulk links must pass `getRouteKey()` — **never** `->id`. Operations
scoped to the current user should use `auth()->user()` directly, not `find(auth()->id())`.
