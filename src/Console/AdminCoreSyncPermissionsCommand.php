<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Ngos\AdminCore\Support\Permissions\PermissionSynchronizer;
use Ngos\AdminCore\Support\Permissions\ResourcePermissionSpec;
use Ngos\AdminCore\Support\Sync\SyncReport;

/**
 * `admin-core:sync-permissions` — reconcile the CRUD permissions the routes enforce into the database.
 *
 * The counterpart to `admin-core:make` (which is now filesystem-only): it discovers every
 * `permission:{action}-{resource}` the routes gate on, then firstOrCreate()s the missing permissions and
 * grants them to the configured role(s). Idempotent — re-running produces identical state. Safe when the
 * database is unavailable (CI / offline / unreachable): it prints the plan and exits without throwing.
 *
 * This is the first command over the {@see \Ngos\AdminCore\Support\Sync\Synchronizer} engine; future
 * `sync-menu` / `sync-routes` / `sync-policies` commands reuse the same discover→plan→apply→report flow.
 */
class AdminCoreSyncPermissionsCommand extends Command
{
    protected $signature = 'admin-core:sync-permissions
        {--guard= : Only sync permissions on this auth guard}
        {--resource=* : Only sync these resources (kebab slug, repeatable)}
        {--role=* : Grant to these roles instead of permission.default_roles (repeatable)}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Create + grant the CRUD permissions the routes enforce (idempotent). Filesystem generation stays in admin-core:make.';

    /**
     * Exit codes (so CI/CD — GitHub Actions, GitLab CI, Jenkins, Azure DevOps — can detect a failed sync):
     *   0  success — permissions synced, OR a --dry-run plan printed, OR nothing to do (disabled / none found).
     *   2  the permissions store (database) was UNREACHABLE during a real (non --dry-run) run — nothing was
     *      written. A --dry-run against an unreachable store still exits 0 (it only reports the plan).
     */
    public const EXIT_STORE_UNAVAILABLE = 2;

    public function handle(PermissionSynchronizer $synchronizer): int
    {
        if (! config('admin-core.permission.enabled')) {
            $this->components->info('admin-core permissions are disabled (permission.enabled = false) — nothing to sync.');

            return self::SUCCESS;
        }

        if ($roles = $this->option('role')) {
            $synchronizer->useRoles($roles);
        }

        $specs = $this->filter($synchronizer->discover());

        if ($specs === []) {
            $this->components->warn('No route-enforced CRUD permissions discovered — is permission.enabled true and are the resource routes registered?');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $synchronizer->available()) {
            $this->renderUnavailable($specs, $dryRun);

            // Never throw. A dry-run only reports the plan → success (0). A real run failed to synchronize →
            // non-zero (2) so CI/CD detects it.
            return $dryRun ? self::SUCCESS : self::EXIT_STORE_UNAVAILABLE;
        }

        $report = $synchronizer->synchronize($specs, apply: ! $dryRun);
        $this->renderReport($report);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, ResourcePermissionSpec>  $specs
     * @return array<string, ResourcePermissionSpec>
     */
    private function filter(array $specs): array
    {
        $guard = $this->option('guard');
        $resources = $this->option('resource');

        return array_filter($specs, function (ResourcePermissionSpec $spec) use ($guard, $resources) {
            if ($guard && $spec->guard !== $guard) {
                return false;
            }

            return ! $resources || in_array($spec->resource, $resources, true);
        });
    }

    /** @param array<string, ResourcePermissionSpec> $specs */
    private function renderUnavailable(array $specs, bool $dryRun): void
    {
        $this->newLine();
        $this->warn('Database unavailable.');
        $this->newLine();

        $this->line('<info>Discovered:</info>');
        foreach ($specs as $spec) {
            $this->line('    '.$spec->label());
        }

        $this->newLine();
        $this->line('<info>Would create:</info>');
        foreach ($specs as $spec) {
            foreach ($spec->permissionNames() as $name) {
                $this->line('    '.$name);
            }
        }

        $this->newLine();
        $this->line($dryRun
            ? 'Dry run — no changes written. This plan is informational (exit 0).'
            : '<fg=red>No changes written — the database was unreachable (exit '.self::EXIT_STORE_UNAVAILABLE.'). Re-run against a reachable database to apply.</>');
    }

    private function renderReport(SyncReport $report): void
    {
        $dryRun = ! $report->applied;
        $this->newLine();

        foreach ($report->sections() as $section => $buckets) {
            $this->line("<info>{$section}</info>");
            $this->renderBucket($dryRun ? 'would create' : 'created', $buckets[$dryRun ? 'would-create' : 'created'] ?? []);
            if ($dryRun) {
                $this->renderBucket('would grant', $buckets['would-grant'] ?? []);
            } else {
                $this->renderBucket('granted', $buckets['granted'] ?? []);
            }
            $this->newLine();
        }

        $verb = $dryRun ? 'would create' : 'created';
        $created = count($report->bucket($dryRun ? 'would-create' : 'created'));
        $existing = count($report->bucket('existing'));
        $this->components->info(sprintf(
            '%s %d permission(s)%s across %d resource(s)%s.',
            ucfirst($verb),
            $created,
            $existing ? ", {$existing} already present" : '',
            count($report->sectionNames()),
            $dryRun ? ' (dry run — nothing written)' : '',
        ));
    }

    /** @param list<string> $items */
    private function renderBucket(string $label, array $items): void
    {
        if ($items === []) {
            return;
        }
        $this->line("  {$label}:");
        foreach ($items as $item) {
            $this->line('    '.$item);
        }
    }
}
