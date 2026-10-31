<?php

/*
 * scripts/test.sh --changed --list: which tests a change selects, in a throwaway repository shaped like this one.
 * TEST_SH_IN_SLOT skips the heavy.sh slot; --list prints the selection and runs nothing.
 */

use Symfony\Component\Process\Process;

const TSS_ALWAYS = [
    'tests/Arch', 'tests/Extensions', 'tests/Feature/Auth', 'tests/Feature/DashboardTest.php', 'tests/Feature/Integrations', 'tests/Feature/Migration',
    'tests/Feature/Reports', 'tests/Feature/Review', 'tests/Feature/ReviewProbes', 'tests/Feature/Security', 'tests/Feature/Settings', 'tests/Feature/TestHarness',
];

function tssGit(string $dir, string ...$args): string
{
    $process = new Process(['git', '-c', 'user.name=test', '-c', 'user.email=test@localhost', '-c', 'commit.gpgsign=false', ...$args], $dir);
    $process->setTimeout(30)->mustRun();

    return trim($process->getOutput());
}

function tssWrite(string $dir, string $path, string $content = "<?php\n"): void
{
    @mkdir(dirname("{$dir}/{$path}"), 0700, true);
    file_put_contents("{$dir}/{$path}", $content);
}

/** @return array{0: string, 1: string} the repository (its directory name ends in $suffix) and its base commit */
function tssRepo(string $suffix = ''): array
{
    $dir = sys_get_temp_dir().'/test-sh-'.bin2hex(random_bytes(4)).$suffix;

    foreach ([...TSS_ALWAYS, 'tests/Feature/Billing', 'tests/Unit/Billing', 'tests/Unit/Deploy', 'tests/Feature/Kyc/Support'] as $tests) {
        str_ends_with($tests, '.php') ? tssWrite($dir, "billing/{$tests}") : tssWrite($dir, "billing/{$tests}/ExampleTest.php");
    }
    // A test outside the Billing directories that uses a Billing class.
    tssWrite($dir, 'billing/tests/Feature/Orders/CheckoutTest.php', "<?php\nuse App\\Modules\\Billing\\Invoice;\n");
    tssWrite($dir, 'billing/tests/Feature/Kyc/ReviewTest.php');
    tssWrite($dir, 'billing/app/Modules/Billing/Invoice.php');
    tssWrite($dir, 'billing/app/Modules/Core/Client.php');
    tssWrite($dir, 'billing/app/Support/Money.php');
    tssWrite($dir, 'billing/resources/js/app.tsx', "export {};\n");
    tssWrite($dir, 'docs/notes.md', "# Notes\n");
    tssGit($dir, 'init', '-q', '-b', 'develop');
    tssGit($dir, 'add', '-A');
    tssGit($dir, 'commit', '-qm', 'base');

    return [$dir, tssGit($dir, 'rev-parse', 'HEAD')];
}

function tssProcess(string $dir, string $base, string ...$options): Process
{
    $process = new Process(['bash', dirname(__DIR__, 4).'/scripts/test.sh', '--list', '--changed', ...$options], "{$dir}/billing", [
        'TEST_SH_IN_SLOT' => '1',
        'TEST_BASE' => $base,
    ]);
    $process->setTimeout(30)->run();

    return $process;
}

/** @return list<string> the selection, without the macOS linux.sh note */
function tssList(string $dir, string $base, string ...$options): array
{
    $process = tssProcess($dir, $base, ...$options);
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return array_map(fn (string $line) => str_replace(' (through scripts/linux.sh)', '', $line), array_values(array_filter(explode("\n", $process->getOutput()))));
}

afterEach(function () {
    if (isset($this->repo)) {
        (new Process(['rm', '-rf', $this->repo]))->run();
    }
});

test('a module change selects its Feature and Unit tests, the tests that use its classes and the cross-module suites', function () {
    [$this->repo, $base] = tssRepo();
    tssWrite($this->repo, 'billing/app/Modules/Billing/Invoice.php', "<?php\n// changed\n");

    $selection = tssList($this->repo, $base);
    $expected = [...TSS_ALWAYS, 'tests/Feature/Billing', 'tests/Feature/Orders/CheckoutTest.php', 'tests/Unit/Billing'];
    sort($selection);
    sort($expected);

    expect($selection)->toBe($expected);
});

test('a Core change runs the whole suite, since nearly every test depends on Core', function () {
    [$this->repo, $base] = tssRepo();
    tssWrite($this->repo, 'billing/app/Modules/Core/Client.php', "<?php\n// changed\n");

    expect(tssList($this->repo, $base))->toContain('whole suite');
});

test('a frontend change selects every Feature test, where the Inertia components are asserted', function () {
    [$this->repo, $base] = tssRepo();
    tssWrite($this->repo, 'billing/resources/js/app.tsx', "export const changed = 1;\n");

    expect(tssList($this->repo, $base))->toContain('tests/Feature', 'tests/Arch')->not->toContain('tests/Feature/Auth');
});

test('a base ref that does not exist fails instead of selecting nothing', function () {
    [$this->repo] = tssRepo();
    tssWrite($this->repo, 'billing/app/Modules/Billing/Invoice.php', "<?php\n// changed\n");

    $process = tssProcess($this->repo, 'origin/nope');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('base origin/nope is not a commit');
});

test('a committed test file and an untracked helper select the file and the helper\'s directory', function () {
    [$this->repo, $base] = tssRepo();
    tssWrite($this->repo, 'billing/tests/Unit/Deploy/ExampleTest.php', "<?php\n// changed\n");
    tssGit($this->repo, 'commit', '-qam', 'change');
    tssWrite($this->repo, 'billing/tests/Feature/Kyc/Support/worker.php');

    expect(tssList($this->repo, $base))->toContain('tests/Unit/Deploy/ExampleTest.php', 'tests/Feature/Kyc/Support', ...TSS_ALWAYS);
});

test('a change to shared code runs the whole suite', function () {
    [$this->repo, $base] = tssRepo();
    tssWrite($this->repo, 'billing/app/Support/Money.php', "<?php\n// changed\n");

    expect(tssList($this->repo, $base))->toContain('whole suite');
});

test('a docs-only change selects no tests', function () {
    [$this->repo, $base] = tssRepo();
    tssWrite($this->repo, 'docs/notes.md', "# Changed\n");

    expect(implode("\n", tssList($this->repo, $base)))->toContain('no tests relate to the changes');
});

test('on PostgreSQL the Unit and Arch tests are left out of the selection', function () {
    [$this->repo, $base] = tssRepo();
    tssWrite($this->repo, 'billing/app/Modules/Billing/Invoice.php', "<?php\n// changed\n");

    $selection = tssList($this->repo, $base, '--pgsql');

    expect($selection)->toContain('tests/Feature/Billing', 'tests/Feature/Security')
        ->not->toContain('tests/Arch')->not->toContain('tests/Unit/Billing');
});

test('several selected paths run in parallel as one testsuite of a temporary phpunit.xml, since paratest takes one path', function (string $suffix) {
    [$this->repo, $base] = tssRepo($suffix);
    copy(dirname(__DIR__, 3).'/phpunit.xml', "{$this->repo}/billing/phpunit.xml");
    tssGit($this->repo, 'add', '-A');
    tssGit($this->repo, 'commit', '-qm', 'phpunit.xml');
    $base = tssGit($this->repo, 'rev-parse', 'HEAD');
    tssWrite($this->repo, 'billing/app/Modules/Billing/Invoice.php', "<?php\n// changed\n");

    $process = new Process(['bash', dirname(__DIR__, 4).'/scripts/test.sh', '--changed'], "{$this->repo}/billing", [
        'TEST_SH_IN_SLOT' => '1', 'TEST_SH_DRY_RUN' => '1', 'TEST_BASE' => $base, 'TEST_PROCESSES' => '4',
    ]);
    $process->setTimeout(30)->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $output = $process->getOutput();
    preg_match('/^pest (.*)$/m', $output, $command);
    preg_match('/--configuration=(\S+)/', $command[1] ?? '', $config);
    $xml = simplexml_load_string(substr($output, (int) strpos($output, '<?xml')));
    // The worktree's billing/ as git reports it (macOS: /private/var for /var).
    $app = dirname((string) $xml['bootstrap'], 2);
    $suite = array_map('strval', $xml->xpath('//testsuite/directory | //testsuite/file'));

    expect($command[1] ?? '')->toStartWith('--parallel --processes=4 --configuration=')->not->toContain('tests/')
        ->and($config[1] ?? '')->not->toStartWith($app)
        ->and(file_exists($config[1] ?? ''))->toBeFalse()
        ->and($xml->testsuites->testsuite)->toHaveCount(1)
        ->and($suite)->toContain("{$app}/tests/Feature/Billing", "{$app}/tests/Unit/Billing", "{$app}/tests/Feature/DashboardTest.php")
        ->and(realpath($app))->toBe(realpath("{$this->repo}/billing"))
        ->and((string) $xml['bootstrap'])->toBe("{$app}/vendor/autoload.php")
        ->and((string) $xml->source->include->directory)->toBe("{$app}/app")
        ->and($xml->xpath('//env[@name="APP_ENV"]'))->toHaveCount(1);
})->with([
    'a plain path' => [''],
    // XML-special characters and the ones awk treats specially in a replacement or a -v value.
    'a path with & < > quotes and a backslash' => [' a&b <c> \'d\' "e" \\f'],
]);
