<?php

namespace Ngos\AdminCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AdminCoreUninstallCommand extends Command
{
    protected $signature = 'admin-core:uninstall
                            {--purge : Also delete the package-owned files admin-core published}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Reverse admin-core:install — un-wire the routes/middleware it added (and with --purge, delete the files it published). Never touches admin-core:make-generated resources.';

    public function handle(): int
    {
        $purge = $this->option('purge');

        if ($purge) {
            // Informed consent: --purge deletes owned paths whether or not you edited them, so a customised
            // layout / app.scss / dashboard view goes too. Show the count + spell out that local edits are lost.
            $existing = array_filter($this->ownedFiles(), fn ($f) => File::exists($f) && ! File::isDirectory($f));
            $warning = 'This will un-wire admin-core AND permanently delete the ' . count($existing) . ' published '
                . 'file(s) (config, layout, access module, front-end kit) — INCLUDING any local edits you made to '
                . 'them. Back up anything you customised first.';
        } else {
            $warning = 'This will un-wire admin-core (routes, middleware alias, User trait). Published files stay on disk.';
        }
        $this->warn($warning);

        if (! $this->option('force') && ! $this->confirm('Continue?', false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->unwire();

        if ($purge) {
            $this->purgeFiles();
            $this->unmergePackageJson();
        }

        $this->newLine();
        $this->info('admin-core uninstalled' . ($purge ? ' and purged.' : ' (files left in place — re-run with --purge to delete them).'));

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Un-wire: remove the sentinel blocks + revert the User trait.
    // ------------------------------------------------------------------

    private function unwire(): void
    {
        $web = base_path('routes/web.php');
        foreach (['admin-core:routes', 'admin-core:auth'] as $marker) {
            if ($this->stripBlock($web, $marker)) {
                $this->line("  <info>removed</info> {$marker} block from routes/web.php");
            }
        }

        // routes/api.php: the Passport auth routes (which point at the --api-auth AuthController) and the
        // `--api` module loader. They are admin-core's wiring, so they go when we un-wire; with --purge the
        // AuthController is then deleted too (so the stripped block can't dangle against a missing class).
        $api = base_path('routes/api.php');
        foreach (['admin-core:api-auth', 'admin-core:api-modules'] as $marker) {
            if ($this->stripBlock($api, $marker)) {
                $this->line("  <info>removed</info> {$marker} block from routes/api.php");
            }
        }

        if ($this->stripBlock(base_path('bootstrap/app.php'), 'admin-core:middleware')) {
            $this->line('  <info>removed</info> permission middleware alias from bootstrap/app.php');
        }

        $this->unregisterApiAuthProvider();
        $this->revertHasRoles();
    }

    /**
     * --api-auth registers App\Providers\ApiAuthServiceProvider in bootstrap/providers.php. That
     * registration is admin-core's wiring (like the middleware alias), so un-wiring must drop it —
     * otherwise --purge deletes the provider file but leaves a registration pointing at a missing class,
     * which fatals on the next boot.
     */
    private function unregisterApiAuthProvider(): void
    {
        $file = base_path('bootstrap/providers.php');
        if (! File::exists($file)) {
            return;
        }
        $contents = File::get($file);
        $new = preg_replace('/^[ \t]*App\\\\Providers\\\\ApiAuthServiceProvider::class,[ \t]*\n/m', '', $contents);

        if ($new !== null && $new !== $contents) {
            File::put($file, $new);
            $this->line('  <info>removed</info> ApiAuthServiceProvider from bootstrap/providers.php');
        }
    }

    private function stripBlock(string $file, string $marker): bool
    {
        if (! File::exists($file)) {
            return false;
        }
        $contents = File::get($file);
        $pattern = '/\n?\/\/ >>> ' . preg_quote($marker, '/') . '.*?\/\/ <<< ' . preg_quote($marker, '/') . '\n?/s';
        $new = preg_replace($pattern, "\n", $contents, 1);

        if ($new !== null && $new !== $contents) {
            File::put($file, rtrim($new) . "\n");

            return true;
        }

        return false;
    }

    private function revertHasRoles(): void
    {
        $model = app_path('Models/User.php');
        if (! File::exists($model)) {
            return;
        }
        $contents = File::get($model);
        if (! str_contains($contents, 'HasRoles')) {
            return;
        }

        // Remove the imports admin-core added…
        $contents = str_replace("\nuse Spatie\\Permission\\Traits\\HasRoles;", '', $contents);
        $contents = str_replace("\nuse Ngos\\AdminCore\\Concerns\\HasPublicUuid;", '', $contents);
        $contents = str_replace("\nuse Ngos\\AdminCore\\Concerns\\TwoFactorAuthenticatable;", '', $contents);
        // …then strip the traits from the class `use …;` line, whatever order/other traits sit there.
        // (install adds them with a leading comma: `…Notifiable, HasRoles, HasPublicUuid, TwoFactorAuthenticatable;`.)
        // The old exact-match `…Notifiable, HasRoles;` never matched once more traits were added, leaving
        // the model using a trait with no import — a fatal "Trait not found".
        $contents = preg_replace('/,\s*(HasRoles|HasPublicUuid|TwoFactorAuthenticatable)\b/', '', $contents);

        File::put($model, $contents);
        $this->line('  <info>reverted</info> HasRoles / HasPublicUuid / TwoFactorAuthenticatable traits on app/Models/User.php');
    }

    // ------------------------------------------------------------------
    // Purge: delete the package-owned published files.
    // ------------------------------------------------------------------

    private function purgeFiles(): void
    {
        $deleted = 0;
        foreach ($this->ownedFiles() as $target) {
            if (File::exists($target) && ! File::isDirectory($target)) {
                File::delete($target);
                $deleted++;
            }
        }
        $this->removeEmptyDirs();
        $this->line("  <info>deleted</info> {$deleted} published files");
    }

    /** Every path admin-core:install could have created — derived from the stub dirs. */
    private function ownedFiles(): array
    {
        $files = [
            config_path('admin-core.php'),
            config_path('class.php'),
            resource_path('views/backend/dashboard.blade.php'),
            resource_path('views/backend/layouts/app.blade.php'),
            base_path('routes/Web/Backend/Modules/assessments.php'),
            // The three fixed-name migrations install copies from the top-level stubs dir (NOT the access/
            // tree, so the map walk below never sees them). The distinctive 000020–000022 names are ours,
            // never the framework's own timestamped migrations.
            database_path('migrations/0001_01_01_000020_create_activity_logs_table.php'),
            database_path('migrations/0001_01_01_000021_create_notifications_table.php'),
            database_path('migrations/0001_01_01_000022_create_error_logs_table.php'),
        ];

        // The --api-auth footprint (stubs/api-auth) is published ONLY by `install --api-auth`, to a CONVENTIONAL
        // host path (app/Http/Controllers/Api/AuthController.php). Claiming it unconditionally meant `--purge`
        // deleted a host's OWN API auth controller when admin-core never installed one (install even refuses to
        // overwrite it). Gate on an install-here signal: ApiAuthServiceProvider.php is an admin-core-specific
        // name a host won't own, and unwire() only UN-registers it (doesn't delete the file), so it survives to
        // purge time. Independent of --access, so a separate gate from frontendKitInstalled().
        if ($this->apiAuthInstalled()) {
            $files[] = app_path('Http/Controllers/Api/AuthController.php');
            $files[] = app_path('Providers/ApiAuthServiceProvider.php');
        }

        // The frontend kit + access module (the `$map` tree) is published ONLY by `install --access`. On a
        // minimal install those destinations hold the FRAMEWORK's own files (e.g. resources/js/app.js) — not
        // ours — so purging them by path would delete the user's/framework's files. Only claim the tree when
        // the kit was actually installed here.
        if ($this->frontendKitInstalled()) {
            // The account module's route is copied to a fixed path by `install --access` (like assessments.php),
            // not via a walked stub dir — claim it here so --purge doesn't orphan it.
            $files[] = base_path('routes/Web/Backend/Modules/account.php');

            // The auth views are published ONLY by installFrontend() (i.e. --access) to the SAME paths Laravel
            // Breeze/UI use. Claiming them unconditionally meant a minimal install followed by --purge deleted the
            // HOST's own login/two-factor view (breaking /login). Gate them behind the kit like everything else.
            $files[] = resource_path('views/auth/login.blade.php');
            $files[] = resource_path('views/auth/two-factor-challenge.blade.php');

            $map = [
                'frontend/resources' => resource_path(),
                'frontend/views/backend' => resource_path('views/backend'),
                'access/Models' => app_path('Models'),
                'access/Auth' => app_path('Http/Controllers/Auth'),
                'access/Http' => app_path('Http'),
                'access/Services' => app_path('Services'),
                'access/database/seeders' => database_path('seeders'),
                'access/database/migrations' => database_path('migrations'),
                'access/views/backend' => resource_path('views/backend'),
            ];

            foreach ($map as $rel => $dest) {
                $src = __DIR__ . '/../../stubs/' . $rel;
                if (! File::isDirectory($src)) {
                    continue;
                }
                foreach (File::allFiles($src) as $file) {
                    $relative = ltrim(str_replace($src, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                    $files[] = $dest . DIRECTORY_SEPARATOR . preg_replace('/\.stub$/', '', $relative);
                }
            }
        }

        return array_unique($files);
    }

    /**
     * Was the --access frontend kit actually installed here? Detected by an admin-core-specific asset a minimal
     * (config-only) install never publishes and a default Laravel app doesn't have.
     */
    private function frontendKitInstalled(): bool
    {
        return File::exists(resource_path('js/datatable.js')) || File::exists(resource_path('sass/app.scss'));
    }

    /**
     * Was the --api-auth kit actually installed here? Detected by the ApiAuthServiceProvider class file — an
     * admin-core-specific name a host won't own, published only by `install --api-auth`. unwire() only strips its
     * registration (not the file), so this signal survives to purge time. Guards against deleting a host's own
     * conventional app/Http/Controllers/Api/AuthController.php that admin-core never created.
     */
    private function apiAuthInstalled(): bool
    {
        return File::exists(app_path('Providers/ApiAuthServiceProvider.php'));
    }

    private function removeEmptyDirs(): void
    {
        $dirs = [
            resource_path('views/backend/pages/assessments'),
            resource_path('views/backend/pages/menu'),
            app_path('Http/Controllers/Backend/Assessments'),
            app_path('Http/Requests/User'),
            app_path('Http/Requests/Role'),
            app_path('Http/Requests/GroupPermission'),
            app_path('Http/Requests/Menu'),
            app_path('Http/Requests/Profile'),
            app_path('Http/Requests/Setting'),
            app_path('Services/Users'),
            app_path('Services/Roles'),
            app_path('Services/Permissions'),
            app_path('Services/GroupPermissions'),
            app_path('Services/Menu'),
            app_path('Services/ActivityLogs'),
            app_path('Services/ErrorLogs'),
            app_path('Services/Profile'),
            app_path('Services/Settings'),
        ];
        foreach ($dirs as $dir) {
            if (File::isDirectory($dir) && empty(File::allFiles($dir))) {
                File::deleteDirectory($dir);
            }
        }
    }

    private function unmergePackageJson(): void
    {
        $pkgPath = base_path('package.json');
        $stub = __DIR__ . '/../../stubs/frontend/package.json.stub';
        if (! File::exists($pkgPath) || ! File::exists($stub)) {
            return;
        }

        $host = json_decode(File::get($pkgPath), true) ?: [];
        $remove = json_decode(File::get($stub), true) ?: [];

        foreach (['dependencies', 'devDependencies'] as $section) {
            foreach (($remove[$section] ?? []) as $dep => $version) {
                // Remove a dep ONLY when the host's value still EQUALS the stub's — i.e. admin-core added it. A
                // host that pre-declared its own version of jquery/bootstrap kept that version at merge (host
                // wins), so it differs from the stub and is preserved here. Prevents --purge from deleting a
                // host-owned dependency and breaking the build (the merge/unmerge were asymmetric before).
                if (($host[$section][$dep] ?? null) === $version) {
                    unset($host[$section][$dep]);
                }
            }
        }

        File::put($pkgPath, json_encode($host, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->line('  <info>updated</info> package.json (removed admin-core deps — run npm install)');
    }
}
