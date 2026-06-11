# Upgrading

`ngos/admin-core` follows semver-ish tags. Most upgrades are `composer update` + a
re-build of the front-end. The notes below cover the releases that need a manual step.
See `CHANGELOG.md` for the full per-version list.

After any release that touches the theme or JS, rebuild the front-end:

```bash
npm install && npm run build
```

---

## → v1.14.0 — More field types

No breaking change. The `--fields` DSL gains `time`, `url`, `slug`, `json`, and `password`. Examples:

```bash
php artisan admin-core:make Article --migration --fields="\
  name:string, slug:slug, website:url, start_at:time, meta:json, secret:password"
```

`slug` is auto-derived from `name` when blank; `json` round-trips through a textarea (array cast);
`password` is hashed and a blank value on edit keeps the current one. Existing resources are unaffected.

## → v1.13.0 — Model casts

No breaking change. Newly generated models declare a `casts()` method for boolean / date / datetime /
decimal columns, so those attributes come back as `bool` / `Carbon` / fixed-precision strings instead of
raw DB values. To add it to an existing model:

```php
protected function casts(): array
{
    return [
        'is_active'    => 'boolean',
        'published_at' => 'datetime',
        'price'        => 'decimal:2',
    ];
}
```

## → v1.12.0 — Hybrid-key edit fix + page-header on create/edit/show

**Action required if you generated resources before v1.12.0 *and* use `--uuid` (hybrid keys).**
Older generated `edit.blade.php` / `show.blade.php` keyed their route links by the bigint
`id` instead of the public route key, so saving an edit resolved `uuid = <int>` and crashed
with an invalid-uuid SQL error. Patch the two links in each generated resource:

```blade
{{-- edit.blade.php — form action --}}
- <form action="{{ route('admin.posts.update', $object->id) }}" method="POST">
+ <form action="{{ route('admin.posts.update', $object->getRouteKey()) }}" method="POST">

{{-- show.blade.php — Edit link --}}
- <a href="{{ route('admin.posts.edit', $object->id) }}" ...>
+ <a href="{{ route('admin.posts.edit', $object->getRouteKey()) }}" ...>
```

Also new: `create` / `edit` / `show` now use `<x-admin-core::page-header>` (matching the index),
and the component gained optional `parent` + `parentUrl` props for a sub-page crumb
(`Dashboard › Posts › Edit`):

```blade
<x-admin-core::page-header title="Edit Post" parent="Posts"
    :parent-url="route('admin.posts.index')" />
```

## → v1.11.0 — Enum status pills

No breaking change. Newly generated resources render their **enum** column as a soft
`.ac-status` pill (semantic colours for common status words, neutral fallback) in both the
table and the show view. To apply it to an existing column, wrap the value:

```blade
<span class="ac-status" data-status="{{ $object->status }}">{{ $object->status }}</span>
```

In a DataTables cell, do it in `editColumn` and add the column to `rawColumns`.

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
