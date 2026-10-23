<?php

/*
 * scripts/test.sh --changed --list: which tests a change selects, in a throwaway repository shaped like this one.
 * TEST_SH_IN_SLOT skips the heavy.sh slot; --list prints the selection and runs nothing.
 */

use Symfony\Component\Process\Process;

const TSS_ALWAYS = ['tests/Arch', 'tests/Extensions', 'tests/Feature/Migration', 'tests/Feature/Security', 'tests/Feature/TestHarness'];

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

/** @return array{0: string, 1: string} the repository and its base commit */
function tssRepo(): array
{
    $dir = sys_get_temp_dir().'/test-sh-'.bin2hex(random_bytes(4));

    foreach ([...TSS_ALWAYS, 'tests/Feature/Billing', 'tests/Unit/Billing', 'tests/Unit/Deploy', 'tests/Feature/Kyc/Support'] as $tests) {
        tssWrite($dir, "billing/{$tests}/ExampleTest.php");
    }
    tssWrite($dir, 'billing/app/Modules/Billing/Invoice.php');
    tssWrite($dir, 'billing/app/Support/Money.php');
    tssWrite($dir, 'billing/resources/js/app.tsx', "export {};\n");
    tssWrite($dir, 'docs/notes.md', "# Notes\n");
    tssGit($dir, 'init', '-q', '-b', 'develop');
    tssGit($dir, 'add', '-A');
    tssGit($dir, 'commit', '-qm', 'base');

    return [$dir, tssGit($dir, 'rev-parse', 'HEAD')];
}

/** @return list<string> the selection, without the macOS linux.sh note */
function tssList(string $dir, string $base, string ...$options): array
{
    $process = new Process(['bash', dirname(__DIR__, 4).'/scripts/test.sh', '--list', '--changed', ...$options], "{$dir}/billing", [
        'TEST_SH_IN_SLOT' => '1',
        'TEST_BASE' => $base,
    ]);
    $process->setTimeout(30)->mustRun();

    return array_map(fn (string $line) => str_replace(' (through scripts/linux.sh)', '', $line), array_values(array_filter(explode("\n", $process->getOutput()))));
}

afterEach(function () {
    if (isset($this->repo)) {
        (new Process(['rm', '-rf', $this->repo]))->run();
    }
});

test('a module change selects its Feature and Unit tests and the cross-module suites', function () {
    [$this->repo, $base] = tssRepo();
    tssWrite($this->repo, 'billing/app/Modules/Billing/Invoice.php', "<?php\n// changed\n");

    $selection = tssList($this->repo, $base);
    sort($selection);

    expect($selection)->toBe(['tests/Arch', 'tests/Extensions', 'tests/Feature/Billing', 'tests/Feature/Migration', 'tests/Feature/Security', 'tests/Feature/TestHarness', 'tests/Unit/Billing']);
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

test('frontend and docs changes select no PHP tests', function () {
    [$this->repo, $base] = tssRepo();
    tssWrite($this->repo, 'billing/resources/js/app.tsx', "export const changed = 1;\n");
    tssWrite($this->repo, 'docs/notes.md', "# Changed\n");

    expect(implode("\n", tssList($this->repo, $base)))->toContain('no PHP tests relate to the changes');
});

test('on PostgreSQL the Unit and Arch tests are left out of the selection', function () {
    [$this->repo, $base] = tssRepo();
    tssWrite($this->repo, 'billing/app/Modules/Billing/Invoice.php', "<?php\n// changed\n");

    $selection = tssList($this->repo, $base, '--pgsql');

    expect($selection)->toContain('tests/Feature/Billing', 'tests/Feature/Security')
        ->not->toContain('tests/Arch')->not->toContain('tests/Unit/Billing');
});
