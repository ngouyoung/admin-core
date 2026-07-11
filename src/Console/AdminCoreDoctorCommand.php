<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ngos\AdminCore\Support\Permissions\PermissionNaming;
use Ngos\AdminCore\Support\Permissions\PermissionSynchronizer;
use Ngos\AdminCore\Support\Permissions\RouteResourceDiscovery;

/**
 * Detect — and optionally repair — STUB DRIFT in an installed app.
 *
 * admin-core publishes its frontend assets (the JS behaviour in resources/js, the theme SCSS, the
 * layout/sidebar Blade) by copying them out of the package at install time. Those copies then FREEZE:
 * a later package fix to, say, resources/js/datepicker.js never reaches an app that installed an older
 * version. This command compares each published file against the current package version and reports
 * what has drifted (or gone missing), so a security/bug fix doesn't silently sit unapplied.
 *
 *   php artisan admin-core:doctor            # report only (exits non-zero if anything drifted)
 *   php artisan admin-core:doctor --diff     # …and print a unified diff per drifted file
 *   php artisan admin-core:doctor --fix      # update drifted/missing files to the package version
 *
 * --fix overwrites files, so review with `git diff` before committing — your own theme SCSS / layout
 * edits live in these files too. Behaviour files (JS) are the ones that usually carry fixes.
 */
class AdminCoreDoctorCommand extends Command
{
    protected $signature = 'admin-core:doctor
                            {--fix : Update drifted/missing files to the current package version (review with git diff after)}
                            {--diff : Print a unified diff for each drifted file}
                            {--force : With --fix, skip the confirmation prompt}';

    protected $description = 'Report (or --fix) admin-core frontend assets that have drifted from the current package version — frozen copies that never auto-update.';

    /** managed dest path => its top-level managed AREA root (for the deleted-whole-subtree missing check). */
    private array $areaRoots = [];

    public function handle(): int
    {
        // Permission Health runs regardless of the frontend kit — it's a config-install concern.
        $permissionsHealthy = $this->checkPermissionHealth();

        // Only the --access frontend kit publishes these files. On a minimal install the target paths hold the
        // framework's own defaults (e.g. resources/js/app.js) — comparing them to our stubs falsely reports
        // "drifted"/"missing", and --fix --force would overwrite a working minimal app with the theme stub.
        if (! $this->frontendKitInstalled()) {
            $this->line('admin-core frontend kit not installed (run <info>admin-core:install --access</info>) — skipping asset drift check.');

            return $permissionsHealthy ? self::SUCCESS : self::FAILURE;
        }

        $managed = $this->managedFiles();

        $ok = $drift = $missing = [];
        foreach ($managed as $dest => $src) {
            if (File::exists($dest)) {
                File::get($dest) === File::get($src) ? $ok[] = $dest : $drift[] = $dest;
            } elseif (File::isDirectory($this->areaRoots[$dest] ?? dirname($dest))) {
                // Absent + its managed AREA ROOT still present → deleted, so MISSING. Gating on the AREA root
                // (e.g. views/backend), NOT the immediate parent: a wiped WHOLE subtree (views/backend/partials/,
                // @include'd by the layout) removes the parent dir too, which used to drop the file UNREPORTED
                // and give a false "everything in sync" while every admin page 500s on the missing partial.
                $missing[] = $dest;
            }
            // else: this managed area wasn't installed here at all → not this command's concern.
        }

        $this->line(sprintf(
            'admin-core frontend assets: <info>%d up-to-date</info>, <comment>%d drifted</comment>, <comment>%d missing</comment>.',
            count($ok), count($drift), count($missing)
        ));

        $this->report('Drifted (frozen — may be missing package fixes)', $drift);
        $this->report('Missing', $missing);

        if ($this->option('diff')) {
            $this->printDiffs($drift, $managed);
        }

        if ($drift === [] && $missing === []) {
            $this->info('admin-core frontend assets are in sync with the package. ✔');

            return $permissionsHealthy ? self::SUCCESS : self::FAILURE;
        }

        if (! $this->option('fix')) {
            $this->newLine();
            $this->warn('Run `php artisan admin-core:doctor --fix` to update them. JS files usually carry the fixes;');
            $this->warn('your theme SCSS / layout edits live here too, so review with `git diff` before committing.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Refusing to overwrite files non-interactively. Re-run with --force (and review with git diff after).');

                return self::FAILURE;
            }
            if (! $this->confirm('Overwrite the ' . (count($drift) + count($missing)) . ' file(s) with the package version? (review with git diff after)')) {
                $this->line('Aborted.');

                return self::FAILURE;
            }
        }

        foreach (array_merge($drift, $missing) as $dest) {
            File::ensureDirectoryExists(dirname($dest));
            File::copy($managed[$dest], $dest);
            $this->line('  <info>updated</info> ' . $this->relative($dest));
        }
        $this->newLine();
        $this->info('Updated. Review the changes with `git diff`, then rebuild assets (npm run build).');

        return $permissionsHealthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Permission Health — verify the CRUD permissions the routes enforce exist in the database and are
     * granted to the super role, and flag orphan permissions no route enforces. Reports only; the fix is
     * `admin-core:sync-permissions`. Returns true when healthy / not applicable (nothing to recommend).
     */
    private function checkPermissionHealth(): bool
    {
        if (! config('admin-core.permission.enabled')) {
            return true; // permissions disabled — not applicable
        }

        if (! app(PermissionSynchronizer::class)->available()) {
            $this->line('Permission Health: <comment>database unavailable — skipped</comment>.');

            return true; // can't check; not a failure
        }

        $specs = app(RouteResourceDiscovery::class)->discover();
        if ($specs === []) {
            return true;
        }

        /** @var class-string $permModel */
        $permModel = config('admin-core.permission.model', \Spatie\Permission\Models\Permission::class);
        /** @var class-string $roleModel */
        $roleModel = config('admin-core.permission.role_model', \Spatie\Permission\Models\Role::class);
        $rolesExist = Schema::hasTable('roles');

        $missing = $ungranted = [];
        $enforced = [];
        foreach ($specs as $spec) {
            $names = $spec->permissionNames();
            $existing = $permModel::query()->whereIn('name', $names)->where('guard_name', $spec->guard)->pluck('name')->all();

            foreach ($names as $name) {
                $enforced[$spec->guard."\0".$name] = true;
                if (! in_array($name, $existing, true)) {
                    $missing[] = $name;
                }
            }

            $roleName = config("admin-core.permission.guards.{$spec->guard}.super_role")
                ?? config('admin-core.permission.super_role', 'admin');
            if ($rolesExist && $roleName) {
                // Only report ungranted permissions when the super role ACTUALLY EXISTS on this guard —
                // otherwise sync-permissions can't grant them either (it grants only to existing roles), so
                // flagging them would leave doctor permanently red with a remedy that can't clear it. A
                // missing role on a guard (e.g. a portal without its own super_role) is not this check's
                // concern; grant-time simply skips it.
                $role = $roleModel::where('name', $roleName)->where('guard_name', $spec->guard)->first();
                if ($role) {
                    $held = $role->permissions->pluck('name')->all();
                    foreach ($existing as $name) {
                        if (! in_array($name, $held, true)) {
                            $ungranted[] = $name;
                        }
                    }
                }
            }
        }

        // Orphans: CRUD-shaped permission rows that no registered route enforces (a resource was removed).
        $orphans = [];
        foreach ($permModel::query()->get(['name', 'guard_name']) as $perm) {
            if (PermissionNaming::parse($perm->name) !== null && ! isset($enforced[$perm->guard_name."\0".$perm->name])) {
                $orphans[] = $perm->name;
            }
        }

        $this->newLine();
        $this->line('<options=bold>Permission Health</>');
        $this->line('-----------------');

        if ($missing === [] && $ungranted === [] && $orphans === []) {
            $this->info('every route-enforced CRUD permission exists and is granted. ✔');

            return true;
        }

        $this->reportList('route-enforced permissions with no database row', $missing);
        $this->reportList('permissions not granted to the super role', $ungranted);
        $this->reportList('orphan permissions — no route enforces them (informational; not auto-removed)', $orphans);

        // An actionable recommendation — but NEVER run it automatically.
        if ($missing !== [] || $ungranted !== []) {
            $this->newLine();
            $this->warn($missing !== [] ? 'Missing permissions detected.' : 'Ungranted permissions detected.');
            $this->newLine();
            $this->line('Recommended action:');
            $this->line('    <info>php artisan admin-core:sync-permissions</info>');

            return false;
        }

        return true; // only orphans — informational, nothing to sync
    }

    /** @param list<string> $items */
    private function reportList(string $heading, array $items): void
    {
        if ($items === []) {
            return;
        }
        $this->newLine();
        $this->line("  <comment>{$heading}:</comment>");
        foreach ($items as $item) {
            $this->line('    '.$item);
        }
    }

    /**
     * The published frontend files, mapped destination => package source, mirroring exactly what
     * AdminCoreInstallCommand::installFrontend() copies (a verbatim copyTree, .stub stripped).
     *
     * @return array<string, string>
     */
    /**
     * Was the --access frontend kit actually installed here? Detected by an admin-core-specific asset that a
     * minimal (config-only) install never publishes and a default Laravel app doesn't have.
     */
    private function frontendKitInstalled(): bool
    {
        return File::exists(resource_path('js/datatable.js')) || File::exists(resource_path('sass/app.scss'));
    }

    private function managedFiles(): array
    {
        $fe = __DIR__ . '/../../stubs/frontend';
        $managed = [];

        foreach ([[$fe . '/resources', resource_path()], [$fe . '/views/backend', resource_path('views/backend')]] as [$src, $dest]) {
            if (! File::isDirectory($src)) {
                continue;
            }
            foreach (File::allFiles($src) as $file) {
                $relative = ltrim(str_replace($src, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                $target = $dest . DIRECTORY_SEPARATOR . preg_replace('/\.stub$/', '', $relative);
                $managed[$target] = $file->getPathname();
                $this->areaRoots[$target] = $dest; // the managed area this file belongs to (deleted-subtree check)
            }
        }
        foreach ([
            [$fe . '/views/auth/login.blade.php.stub', resource_path('views/auth/login.blade.php')],
            [$fe . '/views/auth/two-factor-challenge.blade.php.stub', resource_path('views/auth/two-factor-challenge.blade.php')],
        ] as [$src, $dest]) {
            if (File::exists($src)) {
                $managed[$dest] = $src;
                $this->areaRoots[$dest] = dirname($dest); // views/auth
            }
        }

        return $managed;
    }

    /** @param array<int, string> $files */
    private function report(string $heading, array $files): void
    {
        if ($files === []) {
            return;
        }
        $this->newLine();
        $this->line("  <comment>{$heading}:</comment>");
        foreach ($files as $dest) {
            $tag = Str::endsWith($dest, '.js') ? ' <fg=yellow>[behaviour]</>' : '';
            $this->line('    ' . $this->relative($dest) . $tag);
        }
    }

    /**
     * @param  array<int, string>  $drift
     * @param  array<string, string>  $managed
     */
    private function printDiffs(array $drift, array $managed): void
    {
        foreach ($drift as $dest) {
            $this->newLine();
            $this->line('<options=bold>── ' . $this->relative($dest) . ' ──</>');
            // Unix `diff`: installed (current) vs package (incoming). Exit 1 = differs (expected).
            $result = Process::run(['diff', '-u', $dest, $managed[$dest]]);
            $this->line(trim($result->output()) ?: $result->errorOutput());
        }
    }

    private function relative(string $path): string
    {
        return Str::after($path, base_path() . DIRECTORY_SEPARATOR);
    }
}
