<?php

/*
 * Test files are named for the behaviour they pin, not for the review that found it: a name like `Pr538Rr2ProbesTest`
 * tells the next reader nothing. A new file may not start with `Pr<digits>` or contain `Round<digit>`
 * (docs/devops/ci.md, "Test file names"). The files that already do are listed in baselines/probe-test-names.txt and
 * are renamed per module by the S1 test-layout work; delete an entry in the same commit as its rename.
 */

use Symfony\Component\Finder\Finder;

/** @return list<string> Paths relative to the billing root, sorted. */
function probeNamedTestFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach ((new Finder)->files()->in([$root.'/tests', $root.'/tools'])->name('*.php')->exclude('vendor') as $file) {
        if (preg_match('/^Pr\d|Round\d/', $file->getBasename()) === 1) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }

    sort($files);

    return $files;
}

/** @return list<string> */
function probeNameBaseline(): array
{
    return array_values(array_filter(array_map('trim', file(__DIR__.'/baselines/probe-test-names.txt') ?: [])));
}

test('new test files are not named after a PR or a review round', function () {
    expect(array_values(array_diff(probeNamedTestFiles(), probeNameBaseline())))->toBe([], 'Rename these by behaviour (not Pr<n>… or …Round<n>…).');
});

test('the probe-name baseline lists only files that still exist and still match', function () {
    expect(array_values(array_diff(probeNameBaseline(), probeNamedTestFiles())))->toBe([], 'Remove these from tests/Arch/baselines/probe-test-names.txt.');
});

afterAll(fn () => releaseArchCache());
