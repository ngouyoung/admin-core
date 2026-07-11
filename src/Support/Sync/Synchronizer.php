<?php

namespace Ngos\AdminCore\Support\Sync;

/**
 * The admin-core Synchronization Engine contract.
 *
 * A Synchronizer reconciles a piece of an application's DESIRED state — derived from the runtime source of
 * truth (registered routes, config, the filesystem) — into a runtime STORE (the database), idempotently and
 * without ever touching the filesystem. It is the counterpart to `admin-core:make`, which now writes only
 * files: generation and synchronization are separate responsibilities.
 *
 * {@see \Ngos\AdminCore\Support\Permissions\PermissionSynchronizer} is the first implementation. Future
 * engines (`sync-menu`, `sync-routes`, `sync-policies`, `sync all`) implement this same contract and reuse
 * {@see SyncReport}, so a shared runner/command can drive any of them the same way.
 */
interface Synchronizer
{
    /** Stable machine key, e.g. `permissions` — the engine selector and the report grouping. */
    public function key(): string;

    /**
     * Is the STORE this engine writes to reachable right now? (a database for the permission/menu engines, a
     * filesystem for a future translation/policy engine). MUST NOT throw — a driver/connection/IO error is
     * reported, never raised, so a caller can degrade to a report-only plan. Storage-agnostic on purpose.
     */
    public function available(): bool;

    /**
     * Read the desired state from the runtime source of truth. Pure read — no mutation, no filesystem.
     * The returned items are opaque to callers and passed straight back to {@see synchronize()}.
     *
     * @return array<mixed>
     */
    public function discover(): array;

    /**
     * Reconcile $discovered into the store, idempotently (running twice yields identical state).
     *
     * @param  array<mixed>  $discovered  items from {@see discover()}, possibly filtered by the caller
     * @param  bool  $apply  false = dry-run: compute the plan and report it, but write nothing
     */
    public function synchronize(array $discovered, bool $apply): SyncReport;
}
