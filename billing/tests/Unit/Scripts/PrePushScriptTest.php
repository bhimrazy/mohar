<?php

/*
 * scripts/pre-push.sh --dry-run: which checks the gate plans for a change, in a throwaway repository.
 * TEST_SH_IN_SLOT skips the heavy.sh slot of the test selection that the gate asks for.
 */

use Symfony\Component\Process\Process;

function ppGit(string $dir, string ...$args): string
{
    $process = new Process(['git', '-c', 'user.name=test', '-c', 'user.email=test@localhost', '-c', 'commit.gpgsign=false', ...$args], $dir);
    $process->setTimeout(30)->mustRun();

    return trim($process->getOutput());
}

function ppWrite(string $dir, string $path, string $content): void
{
    @mkdir(dirname("{$dir}/{$path}"), 0700, true);
    file_put_contents("{$dir}/{$path}", $content);
}

/** @return array{0: string, 1: string} a repository with one commit, and that base commit */
function ppRepo(): array
{
    $dir = sys_get_temp_dir().'/pre-push-'.bin2hex(random_bytes(4));
    ppWrite($dir, 'billing/app/Modules/Billing/Invoice.php', "<?php\n");
    ppWrite($dir, 'billing/app/Modules/Core/Client.php', "<?php\n");
    ppWrite($dir, 'billing/tests/Unit/Billing/ExampleTest.php', "<?php\n");
    ppWrite($dir, 'billing/resources/js/app.tsx', "export {};\n");
    ppWrite($dir, 'billing/docs/notes.md', "# Notes\n");
    ppGit($dir, 'init', '-q', '-b', 'develop');
    ppGit($dir, 'add', '-A');
    ppGit($dir, 'commit', '-qm', 'base');

    return [$dir, ppGit($dir, 'rev-parse', 'HEAD')];
}

/** Commit the given files on top of the base, then return the dry-run plan. */
function ppPlan(string $dir, string $base, array $files, array $env = []): Process
{
    foreach ($files as $path => $content) {
        ppWrite($dir, $path, $content);
    }
    ppGit($dir, 'add', '-A');
    ppGit($dir, 'commit', '-qm', 'change');

    $process = new Process(['bash', dirname(__DIR__, 4).'/scripts/pre-push.sh', '--dry-run'], "{$dir}/billing", [
        'PRE_PUSH_BASE' => $base,
        'TEST_SH_IN_SLOT' => '1',
        ...$env,
    ]);
    $process->setTimeout(30)->run();

    return $process;
}

afterEach(function () {
    if (isset($this->repo)) {
        (new Process(['rm', '-rf', $this->repo]))->run();
    }
});

test('a module change plans pint, phpstan, the changelog check and the mapped tests, and no vp check', function () {
    [$this->repo, $base] = ppRepo();
    $plan = ppPlan($this->repo, $base, ['billing/app/Modules/Billing/Invoice.php' => "<?php\n// changed\n"]);

    expect($plan->getExitCode())->toBe(0, $plan->getErrorOutput())
        ->and($plan->getOutput())
        ->toContain('pint: app/Modules/Billing/Invoice.php')
        ->toContain('phpstan: app/Modules/Billing/Invoice.php')
        ->toContain('changelog: ')
        ->toContain('tests: scripts/test.sh --changed')
        ->toContain('vp: skip');
});

test('a docs-only change plans the changelog check and skips every PHP check and the tests', function () {
    [$this->repo, $base] = ppRepo();
    $plan = ppPlan($this->repo, $base, ['billing/docs/notes.md' => "# Changed\n"]);

    expect($plan->getOutput())
        ->toContain('vp: docs/notes.md')
        ->toContain('changelog: ')
        ->toContain('pint: skip')
        ->toContain('phpstan: skip')
        ->toContain('tests: skip')
        ->not->toContain('tests: scripts/test.sh');
});

test('a frontend change plans vp check and tests, and no pint or phpstan', function () {
    [$this->repo, $base] = ppRepo();
    $plan = ppPlan($this->repo, $base, ['billing/resources/js/app.tsx' => "export const changed = 1;\n"]);

    expect($plan->getOutput())
        ->toContain('vp: resources/js/app.tsx')
        ->toContain('pint: skip')
        ->toContain('phpstan: skip')
        ->toContain('tests: scripts/test.sh --changed');
});

test('a test-only PHP change is linted but not analysed', function () {
    [$this->repo, $base] = ppRepo();
    $plan = ppPlan($this->repo, $base, ['billing/tests/Unit/Billing/ExampleTest.php' => "<?php\n// changed\n"]);

    expect($plan->getOutput())->toContain('pint: tests/Unit/Billing/ExampleTest.php')->toContain('phpstan: skip');
});

test('shared code leaves the whole suite to CI unless PRE_PUSH_FULL is set', function () {
    [$this->repo, $base] = ppRepo();
    $plan = ppPlan($this->repo, $base, ['billing/app/Modules/Core/Client.php' => "<?php\n// changed\n"]);

    expect($plan->getOutput())->toContain('tests: skip (shared code changed');

    $full = new Process(['bash', dirname(__DIR__, 4).'/scripts/pre-push.sh', '--dry-run'], "{$this->repo}/billing", [
        'PRE_PUSH_BASE' => $base, 'TEST_SH_IN_SLOT' => '1', 'PRE_PUSH_FULL' => '1',
    ]);
    $full->run();

    expect($full->getOutput())->toContain('tests: scripts/test.sh --changed');
});

test('PRE_PUSH_SKIP leaves the named checks out of the plan', function () {
    [$this->repo, $base] = ppRepo();
    $plan = ppPlan($this->repo, $base, ['billing/app/Modules/Billing/Invoice.php' => "<?php\n// changed\n"], ['PRE_PUSH_SKIP' => 'tests,phpstan']);

    expect($plan->getOutput())->toContain('pint: app/Modules/Billing/Invoice.php')->not->toContain('tests: scripts')->not->toContain('phpstan: app');
});

test('a missing base skips the gate with a note instead of failing the push', function () {
    [$this->repo] = ppRepo();
    $plan = ppPlan($this->repo, 'origin/nope', ['billing/docs/notes.md' => "# Changed\n"]);

    expect($plan->getExitCode())->toBe(0)->and($plan->getErrorOutput())->toContain('origin/nope not found');
});

test('the hook installer sets core.hooksPath for one worktree only', function () {
    [$this->repo] = ppRepo();
    $root = dirname(__DIR__, 4);
    foreach (['scripts/install-hooks.sh', 'scripts/pre-push.sh', 'scripts/githooks/pre-push'] as $file) {
        ppWrite($this->repo, $file, file_get_contents("{$root}/{$file}"));
    }
    $home = $this->repo.'-home';
    mkdir($home);
    $install = new Process(['bash', "{$this->repo}/scripts/install-hooks.sh"], $this->repo, ['HOME' => $home, 'GIT_CONFIG_GLOBAL' => "{$home}/gitconfig"]);
    $install->mustRun();

    expect(ppGit($this->repo, 'config', '--worktree', 'core.hooksPath'))->toBe(realpath($this->repo).'/scripts/githooks')
        ->and(file_exists("{$home}/gitconfig"))->toBeFalse()
        ->and(is_executable("{$this->repo}/scripts/githooks/pre-push"))->toBeTrue();

    (new Process(['rm', '-rf', $home]))->run();
});

test('a test step past PRE_PUSH_TEST_TIMEOUT is stopped without failing the push', function () {
    [$this->repo, $base] = ppRepo();
    ppWrite($this->repo, 'billing/app/Modules/Billing/Invoice.php', "<?php\n// changed\n");
    ppGit($this->repo, 'commit', '-qam', 'change');
    // A stub test.sh that lists a selection and then hangs; the gate's other checks are skipped.
    $stub = $this->repo.'/stub';
    mkdir($stub.'/scripts', 0700, true);
    foreach (['pre-push.sh', 'heavy.sh', 'changelog-check.sh'] as $file) {
        copy(dirname(__DIR__, 4)."/scripts/{$file}", "{$stub}/scripts/{$file}");
    }
    file_put_contents("{$stub}/scripts/test.sh", "#!/usr/bin/env bash\n[ \"\${2:-}\" = --list ] || [ \"\${3:-}\" = --list ] && { echo tests/Unit; exit 0; }\nsleep 30\n");

    chmod("{$stub}/scripts/test.sh", 0700);

    $run = new Process(['bash', "{$stub}/scripts/pre-push.sh"], "{$this->repo}/billing", [
        'PRE_PUSH_BASE' => $base, 'PRE_PUSH_SKIP' => 'pint,phpstan,changelog,vp', 'PRE_PUSH_TEST_TIMEOUT' => '1', 'HEAVY_LOCK_DIR' => $this->repo.'/locks',
    ]);
    $run->setTimeout(25)->run();

    expect($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput())
        ->and($run->getErrorOutput())->toContain('tests stopped after 1s');
});
