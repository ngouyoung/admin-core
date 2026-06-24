<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

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

    public function handle(): int
    {
        $managed = $this->managedFiles();

        $ok = $drift = $missing = [];
        foreach ($managed as $dest => $src) {
            if (File::exists($dest)) {
                File::get($dest) === File::get($src) ? $ok[] = $dest : $drift[] = $dest;
            } elseif (File::isDirectory(dirname($dest))) {
                // The folder exists but this file doesn't — it was deleted or partially installed.
                $missing[] = $dest;
            }
            // else: this area wasn't installed here at all → not this command's concern.
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
            $this->info('Everything is in sync with the package. ✔');

            return self::SUCCESS;
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

        return self::SUCCESS;
    }

    /**
     * The published frontend files, mapped destination => package source, mirroring exactly what
     * AdminCoreInstallCommand::installFrontend() copies (a verbatim copyTree, .stub stripped).
     *
     * @return array<string, string>
     */
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
            }
        }
        foreach ([
            [$fe . '/views/auth/login.blade.php.stub', resource_path('views/auth/login.blade.php')],
            [$fe . '/views/auth/two-factor-challenge.blade.php.stub', resource_path('views/auth/two-factor-challenge.blade.php')],
        ] as [$src, $dest]) {
            if (File::exists($src)) {
                $managed[$dest] = $src;
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
