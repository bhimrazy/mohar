<?php

use App\Contracts\Reports\Report;

/*
 * Every report in app/ is checked for paging (#403): a report that ignores `limit` and `offset` shows page 1 again as
 * page 2, and nothing at run time notices. A test file must name the report class and call
 * ReportConformance::assertPages(). It finds the reports by source scan, so it needs no database.
 */

/**
 * @return list<string> short class names of the reports implemented in app/
 */
function reportClassesInApp(): array
{
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        $code = $file->getExtension() === 'php' ? (string) file_get_contents($file->getPathname()) : '';

        if (preg_match('/^namespace\s+([\w\\\\]+);/m', $code, $namespace) === 1 && preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $code, $class) === 1) {
            $name = $namespace[1].'\\'.$class[1];

            if (class_exists($name) && is_subclass_of($name, Report::class) && (new ReflectionClass($name))->isInstantiable()) {
                $found[] = $class[1];
            }
        }
    }

    sort($found);

    return $found;
}

test('every report in app/ has a paging conformance test', function () {
    $tests = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), 'Test.php') && str_contains($code = (string) file_get_contents($file->getPathname()), 'ReportConformance::assertPages(')) {
            $tests[] = $code;
        }
    }

    $reports = reportClassesInApp();
    $untested = array_values(array_filter($reports, fn (string $class): bool => ! collect($tests)->contains(fn (string $code): bool => str_contains($code, $class))));

    expect($reports)->toContain('VatRegisterReport', 'TaxNoteRegisterReport')
        ->and($untested)->toBe([]);
});

afterAll(fn () => releaseArchCache());
