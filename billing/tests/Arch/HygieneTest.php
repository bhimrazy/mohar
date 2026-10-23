<?php

/*
 * Hygiene rules (docs/architecture.md → "Dependency rules"). Kept apart from ArchitectureTest.php so the arch
 * plugin's parsed-source cache stays small per file (see the Arch hook in tests/Pest.php).
 */

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('controllers do not query other modules or the DB facade directly')
    ->expect('App\Modules\*\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB']);

// One target per rule (issue #275).
foreach (['App', 'Tests'] as $codeRoot) {
    arch("{$codeRoot} uses no eval")
        ->expect($codeRoot)
        ->not->toUse(['eval']);
}

// SafeCsv is the one CSV writer (ADR 0025): the formula guard lives there.
arch('fputcsv is used only by the SafeCsv writer')
    ->expect('fputcsv')
    ->toOnlyBeUsedIn('App\Support\Csv');

test('every arch target in this file matches at least one class', function () {
    expect(archTargetsWithoutClasses(['App\Modules\*\Http\Controllers', 'App', 'Tests']))->toBe([]);
});

afterAll(fn () => releaseArchCache());
