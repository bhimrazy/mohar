<?php

use App\Contracts\Reports\Report;
use Tests\Support\Reports\UserListReport;

/*
 * Every report in app/ is checked for paging (#403): a report that ignores `limit` and `offset` shows page 1 again as
 * page 2, and nothing at run time notices. Some test file other than this one must pass the report's registry key to
 * the conformance helper, as `assertPages('<key>'`. Reports are found by reading app/ and reflecting on each class,
 * so no database or registry is needed.
 */

/**
 * @return list<class-string<Report>> the instantiable reports implemented in app/
 */
function reportClassesInApp(): array
{
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        $code = $file->getExtension() === 'php' ? (string) file_get_contents($file->getPathname()) : '';

        if (preg_match('/^namespace\s+([\w\\\\]+);/m', $code, $namespace) === 1 && preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $code, $class) === 1) {
            $name = $namespace[1].'\\'.$class[1];

            if (class_exists($name) && is_subclass_of($name, Report::class) && (new ReflectionClass($name))->isInstantiable()) {
                $found[] = $name;
            }
        }
    }

    sort($found);

    return $found;
}

/**
 * The reports whose key no test source passes to the conformance helper.
 *
 * @param  list<class-string<Report>>  $reports
 * @param  list<string>  $sources  test files' contents
 * @return list<class-string<Report>>
 */
function reportsWithoutConformance(array $reports, array $sources): array
{
    return array_values(array_filter($reports, function (string $class) use ($sources): bool {
        $key = (new ReflectionClass($class))->newInstanceWithoutConstructor()->key();
        $call = '/ReportConformance::assertPages\(\s*[\'"]'.preg_quote($key, '/').'[\'"]/';

        return ! collect($sources)->contains(fn (string $code): bool => preg_match($call, $code) === 1);
    }));
}

test('every report in app/ has a paging conformance test', function () {
    $sources = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), 'Test.php') && realpath($file->getPathname()) !== realpath(__FILE__)) {
            $sources[] = (string) file_get_contents($file->getPathname());
        }
    }

    $reports = reportClassesInApp();

    expect($reports)->not->toBeEmpty()
        ->and(reportsWithoutConformance($reports, $sources))->toBe([]);
});

test('the guard reports a report whose key no test passes to the helper', function () {
    expect(reportsWithoutConformance([UserListReport::class], ['<?php ReportConformance::assertPages(\'other_report\', $query);']))->toBe([UserListReport::class])
        ->and(reportsWithoutConformance([UserListReport::class], ['<?php ReportConformance::assertPages(\'user_list\', $query);']))->toBe([]);
});

afterAll(fn () => releaseArchCache());
