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

afterAll(fn () => releaseArchCache());
