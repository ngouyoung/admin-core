<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

/*
 * Regression for the v2.82.1 fix. A menu label ("Courses") that matches a PHP translation GROUP file
 * — exactly what FI-2 per-resource label overrides create at lang/en/courses.php — made __('Courses')
 * resolve to the whole array. Blade's {{ }} then fed that array to htmlspecialchars(), throwing a
 * TypeError (a 500 on every admin page). ac_menu_label() now returns the translation only when it is a
 * string, else the raw label. These tests exercise a REAL filesystem group file + the actual sidebar Blade,
 * which the original FI-2 tests did not.
 */

/**
 * Write the `courses` translation group these tests collide with. Laravel's FileLoader resolves a group name
 * VERBATIM — `"{$path}/{$locale}/{$group}.php"` — so `__('Courses')` reads `lang/en/Courses.php` while
 * `ac_label('courses', …)` reads `lang/en/courses.php`. On a case-INsensitive filesystem (macOS/Windows) both
 * queries hit a single file; on a case-SENSITIVE one (Linux/CI) they are DISTINCT files, so the array-collision
 * precondition (`__('Courses')` returns the group array) only holds when BOTH exist. Writing both filename cases
 * makes the collision deterministic on every filesystem — exactly what FI-2 per-resource label overrides create.
 *
 * @return list<string> every path written (delete them all in the caller's finally)
 */
function ac_write_courses_lang(): array
{
    $contents = "<?php\n\nreturn ['fields' => ['cert_validity_months' => 'Certificate Validity (Months)']];\n";
    // 'Courses.php' feeds __('Courses'); 'courses.php' feeds ac_label('courses', …). On a case-insensitive FS these
    // resolve to one file (idempotent same-content writes); on a case-sensitive FS they are the two files needed.
    $paths = [lang_path('en/Courses.php'), lang_path('en/courses.php')];
    foreach ($paths as $path) {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }

    return $paths;
}

it('renders the real sidebar Blade when a menu label collides with a PHP translation group file (no TypeError)', function () {
    $paths = ac_write_courses_lang();
    try {
        // The collision is real on every filesystem now (both filename cases are written): __('Courses') resolves
        // to the group array.
        expect(__('Courses'))->toBeArray();

        // The actual admin-core sidebar-menu component renders successfully and still shows the label.
        $html = Blade::render('<ul><x-admin-core::sidebar-menu :items="$items" /></ul>', [
            'items' => [['label' => 'Courses', 'url' => '/admin/courses', 'match' => 'admin/courses*']],
        ]);
        expect($html)->toContain('Courses')->not->toContain('Array');

        // E — the FI-2 product override still resolves through the same real group file.
        expect(ac_label('courses', 'cert_validity_months'))->toBe('Certificate Validity (Months)');
    } finally {
        foreach ($paths as $path) {
            File::delete($path);
        }
    }
});

it('ac_menu_label() guards a non-string (array) translation, falling back to the raw label — C', function () {
    $paths = ac_write_courses_lang();
    try {
        expect(__('Courses'))->toBeArray();               // precondition: the collision exists
        expect(ac_menu_label('Courses'))->toBe('Courses'); // C — array → raw label, never "Array", never throws
    } finally {
        foreach ($paths as $path) {
            File::delete($path);
        }
    }
});

it('ac_menu_label() passes a real string translation through (A) and falls back to the raw label when missing (B)', function () {
    // A — a real string translation is returned as-is.
    $key = 'admin-core::admin-core.actions.back';
    expect(__($key))->toBeString();
    expect(ac_menu_label($key))->toBe(__($key));
    // B — a label with no translation resolves to itself (a string, never the raw key of a group).
    expect(ac_menu_label('An Unmatched Menu Label'))->toBe('An Unmatched Menu Label');
});

it('leaves FI-2 ac_label behavior unchanged (D)', function () {
    // No override file → headline default (unchanged by the v2.82.1 patch).
    expect(ac_label('products', 'title'))->toBe('Title');
    expect(ac_label('products', 'cert_validity_months'))->toBe('Cert Validity Months');
});
