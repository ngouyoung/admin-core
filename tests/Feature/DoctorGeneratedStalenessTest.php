<?php

use Illuminate\Support\Facades\File;

/*
 * CG-2 — admin-core:doctor's generated-resource staleness check. It idiom-lints the per-resource files
 * admin-core:make wrote (controllers, trash views) for KNOWN superseded framework idioms and, severity-gated,
 * reddens the build for a security/correctness one. Runs regardless of the frontend kit; here the kit is left
 * uninstalled (doctor skips asset drift), so the exit code is driven purely by the generated-staleness check.
 */

/** Reset every host location the check scans (+ the artifacts a real `admin-core:make` writes). */
function cleanupDoctorFixtures(): void
{
    // Ensure the frontend kit reads as NOT installed, so doctor SKIPS the asset-drift check and its exit code is
    // driven purely by the generated-staleness check under test. Otherwise a leaked kit file from an earlier test
    // file makes doctor exit non-zero for an unrelated reason, breaking these tests' exit-code assertions.
    File::delete(resource_path('js/datatable.js'));
    File::delete(resource_path('sass/app.scss'));

    File::deleteDirectory(app_path('Http/Controllers/Backend'));
    File::deleteDirectory(resource_path('views/backend/pages'));
    // A real generated resource (AC-3) also writes these — clear them so nothing leaks between tests.
    File::deleteDirectory(app_path('Services/FreshDoctors'));
    File::deleteDirectory(app_path('Http/Requests/FreshDoctor'));
    File::delete(app_path('Models/FreshDoctor.php'));
    File::delete(app_path('Policies/FreshDoctorPolicy.php'));
    File::delete(database_path('factories/FreshDoctorFactory.php'));
    File::delete(database_path('seeders/FreshDoctorSeeder.php'));
    File::delete(base_path('routes/Web/Backend/Modules/fresh_doctors.php'));
    foreach (glob(database_path('migrations/*_create_fresh_doctors_table.php')) ?: [] as $m) {
        File::delete($m);
    }
}

/** Write a generated-style Backend controller carrying (or not) the raw-LIKE filterColumn idiom. */
function writeBackendController(string $class, string $filterColumnBody): void
{
    File::ensureDirectoryExists(app_path('Http/Controllers/Backend'));
    File::put(app_path("Http/Controllers/Backend/{$class}.php"), <<<PHP
    <?php

    namespace App\\Http\\Controllers\\Backend;

    class {$class} extends \\Ngos\\AdminCore\\Http\\Controllers\\WebController
    {
        public function getData(\$relation = null)
        {
            return parent::getData()
                {$filterColumnBody}
                ->make(true);
        }
    }
    PHP);
}

/** Write a generated-style trash view keying its restore route param off the given expression. */
function writeTrashView(string $plural, string $routeKeyExpr): void
{
    File::ensureDirectoryExists(resource_path("views/backend/pages/{$plural}"));
    File::put(resource_path("views/backend/pages/{$plural}/trash.blade.php"), <<<BLADE
    <td class="text-capitalize">{{ ac_related_label(\$item) ?: \$item->id }}</td>
    <form action="{{ route('admin.{$plural}.restore', {$routeKeyExpr}) }}" method="POST"></form>
    BLADE);
}

beforeEach(fn () => cleanupDoctorFixtures());
afterEach(fn () => cleanupDoctorFixtures());

it('flags a generated controller carrying the pre-v2.79.152 raw-LIKE filterColumn, with the full finding shape (AC-1, AC-9)', function () {
    writeBackendController('StaleGizmoController', "->filterColumn('category', fn (\$q, \$keyword) => \$q->whereHas('category', fn (\$rq) => \$rq->where('name', 'like', \"%{\$keyword}%\")))");

    $this->artisan('admin-core:doctor')
        ->expectsOutputToContain('Generated Resource Staleness')                       // distinct heading
        ->expectsOutputToContain('StaleGizmoController.php')                            // (a) the host file
        ->expectsOutputToContain('unescaped')                                          // (b) the idiom
        ->expectsOutputToContain('v2.79.152')                                          // (c) superseding version
        ->expectsOutputToContain('whereLike')                                          // (d) the remedy
        ->assertFailed();                                                              // AC-5: actionable → non-zero
});

it('flags a generated trash view keying a restore route off $item->id (pre-v2.79.27) (AC-2)', function () {
    writeTrashView('stalethings', '$item->id');

    $this->artisan('admin-core:doctor')
        ->expectsOutputToContain('Generated Resource Staleness')
        ->expectsOutputToContain('stalethings/trash.blade.php')
        ->expectsOutputToContain('v2.79.27')
        ->expectsOutputToContain('getRouteKey')
        ->assertFailed();
});

it('does NOT flag a real, current admin-core:make resource — no false red (AC-3, release-blocking)', function () {
    // The gold-standard no-false-positive check: run the CURRENT generator and confirm doctor is silent on its
    // output. A foreign field yields a Search::whereLike filterColumn; --soft-deletes yields a getRouteKey() trash view.
    $this->artisan('admin-core:make', ['name' => 'FreshDoctor', '--fields' => 'name:string, cat_id:foreign:cats', '--soft-deletes' => true])->assertSuccessful();

    // Prove the generated output genuinely exercises BOTH idiom sites with the CURRENT constructs — otherwise a
    // future generator that dropped the filterColumn / trash view would make this pass vacuously.
    $controller = File::get(app_path('Http/Controllers/Backend/FreshDoctorController.php'));
    $trash = File::get(resource_path('views/backend/pages/fresh_doctors/trash.blade.php'));
    expect($controller)->toContain('filterColumn')->toContain('Search::whereLike') // current search escaping
        ->and($trash)->toContain('getRouteKey');                                    // current route-key accessor

    $this->artisan('admin-core:doctor')
        ->doesntExpectOutputToContain('Generated Resource Staleness') // its generated files use only current idioms
        ->assertSuccessful();                                         // nothing else fails either → exit 0 (AC-6)
});

it('does not flag a generated controller a developer hand-edited to add their OWN LIKE (no false red)', function () {
    // A real generated controller (has parent::getData + the current Search::whereLike filterColumn) into which a
    // developer added their own literal LIKE in a custom method — NOT the generated $keyword idiom. Must stay clean.
    writeBackendController('EditedGizmoController',
        "->filterColumn('category', fn (\$q, \$keyword) => \$q->whereHas('category', fn (\$rq) => \\Ngos\\AdminCore\\Support\\Search::whereLike(\$rq, 'name', \$keyword)))"
    );
    File::append(app_path('Http/Controllers/Backend/EditedGizmoController.php'),
        "\n// developer's own filter (not the generated idiom):\n// \$q->where('status', 'like', '%pending%');\n");

    $this->artisan('admin-core:doctor')
        ->doesntExpectOutputToContain('Generated Resource Staleness')
        ->assertSuccessful();
});

it('emits no staleness section when the host has no generated resources (AC-4)', function () {
    // cleanupDoctorFixtures() left no Backend controllers / trash views.
    $this->artisan('admin-core:doctor')
        ->doesntExpectOutputToContain('Generated Resource Staleness')
        ->assertSuccessful(); // AC-6: the absent section contributes nothing to the exit code
});

it('is report-only — --fix leaves the stale generated files byte-for-byte unchanged (AC-8)', function () {
    writeBackendController('StaleGizmoController', "->filterColumn('category', fn (\$q, \$keyword) => \$q->whereHas('category', fn (\$rq) => \$rq->where('name', 'like', \"%{\$keyword}%\")))");
    $before = File::get(app_path('Http/Controllers/Backend/StaleGizmoController.php'));

    $this->artisan('admin-core:doctor', ['--fix' => true, '--force' => true]);

    expect(File::get(app_path('Http/Controllers/Backend/StaleGizmoController.php')))->toBe($before); // untouched
});

it('produces identical findings on repeated runs — the check is deterministic and needs no database (AC-11)', function () {
    writeBackendController('StaleGizmoController', "->filterColumn('category', fn (\$q, \$keyword) => \$q->whereHas('category', fn (\$rq) => \$rq->where('name', 'like', \"%{\$keyword}%\")))");

    // No DB access (permissions disabled in the test env; the check reads files only), so findings are engine-
    // independent — running twice yields the same flagged file and the same non-zero exit.
    $this->artisan('admin-core:doctor')->expectsOutputToContain('StaleGizmoController.php')->assertFailed();
    $this->artisan('admin-core:doctor')->expectsOutputToContain('StaleGizmoController.php')->assertFailed();
});

it('does not lint a hand-written Backend controller that never calls parent::getData()', function () {
    // The scan is gated on the generated-only marker parent::getData(); a bespoke controller with a raw LIKE is
    // not admin-core-generated output and must not be flagged.
    File::ensureDirectoryExists(app_path('Http/Controllers/Backend'));
    File::put(app_path('Http/Controllers/Backend/HandwrittenController.php'), "<?php\n\nnamespace App\\Http\\Controllers\\Backend;\n\nclass HandwrittenController\n{\n    public function search(\$q, \$k) { return \$q->where('name', 'like', \"%{\$k}%\"); }\n}\n");

    $this->artisan('admin-core:doctor')
        ->doesntExpectOutputToContain('Generated Resource Staleness')
        ->assertSuccessful();
});
