<?php

use Ngos\AdminCore\Support\Doctor\GeneratedIdiomCatalog;

/*
 * CG-2 — the catalog of superseded generated idioms doctor idiom-lints for. These tests exercise the matcher
 * over RAW idiom strings (old vs current), independent of the command, and hold the release-blocking gates:
 * no-false-positive (current output never matches), catalog-coverage (the two seed idioms), and the
 * framework-boundary lint (no domain vocabulary).
 */

it('catalogues the two seed idioms with a severity, superseding version and remedy', function () {
    $ids = array_column(GeneratedIdiomCatalog::entries(), 'id');
    expect($ids)->toContain('raw-like-filtercolumn')   // v2.79.152 security
        ->toContain('trash-route-key-id');             // v2.79.27 correctness

    foreach (GeneratedIdiomCatalog::entries() as $e) {
        expect($e)->toHaveKeys(['id', 'appliesTo', 'severity', 'supersededIn', 'description', 'signature', 'remedy'])
            ->and($e['severity'])->toBeIn(['security', 'correctness', 'cosmetic'])
            ->and($e['supersededIn'])->toStartWith('v')
            ->and($e['remedy'])->not->toBe('')
            ->and(@preg_match($e['signature'], ''))->not->toBeFalse(); // signature is a valid PCRE
    }
});

it('flags both pre-v2.79.152 raw-LIKE forms, NOT the current form NOR a hand-written LIKE (AC-3)', function () {
    // Both superseded forms interpolate the generated closure's $keyword (the datatables term).
    $oldForeign = "->filterColumn('category', fn (\$q, \$keyword) => \$q->whereHas('category', fn (\$rq) => \$rq->where('name', 'like', \"%{\$keyword}%\")))";
    $oldTranslatable = "\$sub->orWhere('name->'.\$acLocale, 'like', '%'.\$keyword.'%', 'or')";
    // Current output routes through Search::whereLike — the string 'like' never appears.
    $now = "->filterColumn('category', fn (\$q, \$keyword) => \$q->whereHas('category', fn (\$rq) => \\Ngos\\AdminCore\\Support\\Search::whereLike(\$rq, 'name', \$keyword)))";
    // A developer's OWN hand-written LIKE inside a generated controller — a literal, over their own term, NOT the
    // generated $keyword idiom — must NOT be flagged (contract §2.1: match within the generated construct).
    $handWritten = "public function mine(\$q) { return \$q->where('status', 'like', '%pending%'); }";

    expect(array_column(GeneratedIdiomCatalog::matches('backend-controller', $oldForeign), 'id'))->toBe(['raw-like-filtercolumn'])
        ->and(array_column(GeneratedIdiomCatalog::matches('backend-controller', $oldTranslatable), 'id'))->toBe(['raw-like-filtercolumn'])
        ->and(GeneratedIdiomCatalog::matches('backend-controller', $now))->toBe([])         // current output
        ->and(GeneratedIdiomCatalog::matches('backend-controller', $handWritten))->toBe([]); // a dev's own LIKE
});

it('flags all three pre-v2.79.27 trash $item->id sites but NOT the current getRouteKey() form or the id label fallback (AC-3)', function () {
    $oldRestore = "<form action=\"{{ route('admin.things.restore', \$item->id) }}\" method=\"POST\">";
    $oldForce = "<form action=\"{{ route('admin.things.forceDelete', \$item->id) }}\" method=\"POST\">";
    $oldCheckbox = '<input type="checkbox" class="row-check" value="{{ $item->id }}">'; // drives bulk restore/force
    // The CURRENT trash.stub read from disk: uses $item->getRouteKey() at all three sites AND $item->id only as a
    // label fallback (`ac_related_label($item) ?: $item->id`) — the latter must NOT trip the signature.
    $currentStub = file_get_contents(dirname(__DIR__, 2).'/stubs/views/trash.stub');

    expect(array_column(GeneratedIdiomCatalog::matches('trash-view', $oldRestore), 'id'))->toBe(['trash-route-key-id'])
        ->and(array_column(GeneratedIdiomCatalog::matches('trash-view', $oldForce), 'id'))->toBe(['trash-route-key-id'])
        ->and(array_column(GeneratedIdiomCatalog::matches('trash-view', $oldCheckbox), 'id'))->toBe(['trash-route-key-id'])
        ->and(GeneratedIdiomCatalog::matches('trash-view', $currentStub))->toBe([])   // current stub not flagged
        ->and($currentStub)->toContain('$item->id')                                    // …even though it contains $item->id
        ->and($currentStub)->toContain('$item->getRouteKey()');
});

it('scopes each idiom to its own file kind (a controller idiom does not match a trash view and vice-versa)', function () {
    $like = "\$rq->where('name', 'like', \"%\$k%\")";
    $trash = "route('admin.things.restore', \$item->id)";

    expect(GeneratedIdiomCatalog::matches('trash-view', $like))->toBe([])          // controller idiom, trash kind → none
        ->and(GeneratedIdiomCatalog::matches('backend-controller', $trash))->toBe([]); // trash idiom, controller kind → none
});

it('severity-gates the exit code — the two seed idioms are actionable (security/correctness)', function () {
    expect(GeneratedIdiomCatalog::isActionable('security'))->toBeTrue()
        ->and(GeneratedIdiomCatalog::isActionable('correctness'))->toBeTrue()
        ->and(GeneratedIdiomCatalog::isActionable('cosmetic'))->toBeFalse();

    foreach (GeneratedIdiomCatalog::entries() as $e) {
        expect(GeneratedIdiomCatalog::isActionable($e['severity']))->toBeTrue(); // both seeds redden the build
    }
});

it('keeps the CHANGELOG doctor-flags-generated claims consistent with the shipped catalog (AC-10, docs-integrity)', function () {
    $changelog = file_get_contents(dirname(__DIR__, 2).'/CHANGELOG.md');

    foreach (GeneratedIdiomCatalog::entries() as $e) {
        // Every catalogued idiom is grounded in a real superseding release, and its catalog id appears in the
        // CHANGELOG INSIDE an editor's note — the annotation that replaced the bare overclaim — so a claim can't
        // drift from what the catalog actually detects (rename an id / move the note → this fails).
        expect($changelog)->toContain($e['supersededIn'])
            ->and($changelog)->toContain($e['id']);
        $idPos = strpos($changelog, $e['id']);
        $notePos = strrpos(substr($changelog, 0, $idPos), "Editor's note");
        expect($notePos)->not->toBeFalse()                    // an editor's note precedes the id…
            ->and($idPos - $notePos)->toBeLessThan(300);      // …in the same sentence, not drifted elsewhere
    }
});

it('contains no domain vocabulary — the catalog keys off framework idioms only (Framework Boundary)', function () {
    // Objectively-checkable proxy for the mechanism-not-policy rule: no business-domain noun appears in any entry.
    $domain = ['invoice', 'course', 'employee', 'customer', 'product', 'order', 'sku', 'lesson', 'enrol', 'payment', 'tenant', 'patient'];
    $text = strtolower(json_encode(GeneratedIdiomCatalog::entries()));
    foreach ($domain as $word) {
        expect($text)->not->toContain($word);
    }
});
