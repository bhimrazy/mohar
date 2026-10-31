<?php

/*
 * billing/scripts/check-dependency-age.mjs in a throwaway repository (PR #539 review): a PR must not be able to judge
 * itself. B1: a dependency-age cache committed by the PR supplied its own publish dates and skipped the registry,
 * integrity and binding checks (the reviewer's left-pad@9.9.9 with a fake integrity passed). M-a: a PR could change
 * the settings the audits read. The cases that would reach a registry assert only that the run does not pass.
 */

use Symfony\Component\Process\Process;

function dckGit(string $dir, string ...$args): void
{
    (new Process(['git', '-c', 'user.name=test', '-c', 'user.email=test@localhost', '-c', 'commit.gpgsign=false', ...$args], $dir))
        ->setTimeout(30)->mustRun();
}

function dckWrite(string $dir, string $path, string $content): void
{
    @mkdir(dirname("{$dir}/{$path}"), 0700, true);
    file_put_contents("{$dir}/{$path}", $content);
}

function dckLock(string $version, string $integrity): string
{
    return json_encode([
        'name' => 'app', 'lockfileVersion' => 3, 'requires' => true,
        'packages' => [
            '' => ['name' => 'app', 'dependencies' => ['left-pad' => $version]],
            'node_modules/left-pad' => [
                'version' => $version,
                'resolved' => "https://registry.npmjs.org/left-pad/-/left-pad-{$version}.tgz",
                'integrity' => $integrity,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/** A repository whose develop branch holds the checker and a locked left-pad@1.3.0; HEAD is a branch off it. */
function dckRepo(): string
{
    $dir = realpath(sys_get_temp_dir()).'/dck-'.bin2hex(random_bytes(4));
    foreach (['check-dependency-age.mjs', 'dependency-policy.mjs'] as $script) {
        dckWrite($dir, "billing/scripts/{$script}", (string) file_get_contents(dirname(__DIR__, 3)."/scripts/{$script}"));
    }
    dckWrite($dir, 'billing/package.json', json_encode(['name' => 'app', 'dependencies' => ['left-pad' => '1.3.0']]));
    dckWrite($dir, 'billing/package-lock.json', dckLock('1.3.0', 'sha512-base'));
    dckWrite($dir, 'billing/composer.json', json_encode(['name' => 'x/app', 'config' => ['sort-packages' => true]], JSON_PRETTY_PRINT));
    dckWrite($dir, 'billing/.npmrc', "save-exact=true\nmin-release-age=7\n");
    dckWrite($dir, 'billing/storage/framework/cache/.gitignore', "*\n!.gitignore\n");
    dckGit($dir, 'init', '-q', '-b', 'develop');
    dckGit($dir, 'add', '-A');
    dckGit($dir, 'commit', '-q', '-m', 'base');
    dckGit($dir, 'checkout', '-q', '-b', 'pr');

    return $dir;
}

/** @param  array<string, string|false>  $env */
function dckCheck(string $dir, array $env = []): Process
{
    $process = new Process(['node', 'billing/scripts/check-dependency-age.mjs', '--base=develop'], $dir, [
        'GITHUB_ACTIONS' => false, 'GITHUB_STEP_SUMMARY' => false, 'DEPENDENCY_AGE_CACHE' => false,
        'DEPENDENCY_AGE_EXCEPTIONS' => false, 'MIN_RELEASE_AGE_DAYS' => '7', ...$env,
    ]);
    $process->setTimeout(120)->run();

    return $process;
}

/** The reviewer's bypass: a version that does not exist, with a fake integrity and a forged old publish date. */
function dckForge(string $dir): void
{
    dckWrite($dir, 'billing/package.json', json_encode(['name' => 'app', 'dependencies' => ['left-pad' => '9.9.9']]));
    dckWrite($dir, 'billing/package-lock.json', dckLock('9.9.9', 'sha512-FAKE'));
    dckWrite($dir, 'billing/storage/framework/cache/dependency-age.json', json_encode([
        'npm|left-pad|9.9.9|https://registry.npmjs.org/left-pad/-/left-pad-9.9.9.tgz#sha512-FAKE' => '2020-01-01T00:00:00Z',
    ], JSON_UNESCAPED_SLASHES));
}

afterEach(function () {
    if (isset($this->repo)) {
        (new Process(['rm', '-rf', $this->repo]))->run();
    }
});

it('fails when the change commits a dependency-age cache, before anything is looked up', function () {
    $this->repo = dckRepo();
    dckForge($this->repo);
    dckGit($this->repo, 'add', '-A');
    dckGit($this->repo, 'add', '-f', 'billing/storage/framework/cache/dependency-age.json');
    dckGit($this->repo, 'commit', '-q', '-m', 'bump left-pad');

    $run = dckCheck($this->repo, ['GITHUB_ACTIONS' => 'true']);

    expect($run->getExitCode())->toBe(1)
        ->and($run->getOutput().$run->getErrorOutput())
        ->toContain('billing/storage/framework/cache/dependency-age.json is committed')
        ->not->toContain('Dependency policy: OK')
        ->not->toContain('✓ npm left-pad@9.9.9');
});

it('ignores an untracked cache in GitHub Actions, so the forged date no longer passes', function () {
    $this->repo = dckRepo();
    dckForge($this->repo);
    dckGit($this->repo, 'add', '-A'); // the cache stays untracked (its .gitignore)
    dckGit($this->repo, 'commit', '-q', '-m', 'bump left-pad');

    $run = dckCheck($this->repo, ['GITHUB_ACTIONS' => 'true']);

    // Online: "version not found on the registry" (1); offline: the date cannot be verified (2). Never a pass.
    expect($run->getExitCode())->toBeIn([1, 2])
        ->and($run->getOutput())->not->toContain('Dependency policy: OK')
        ->and($run->getOutput())->not->toContain('✓ npm left-pad@9.9.9');
});

it('fails a change to the audit settings of composer.json or .npmrc unless the PR carries the label', function (string $path, Closure $change, string $named) {
    $this->repo = dckRepo();
    dckWrite($this->repo, $path, $change((string) file_get_contents("{$this->repo}/{$path}")));
    dckGit($this->repo, 'commit', '-qam', 'mute the audit');

    $unlabelled = dckCheck($this->repo);
    $labelled = dckCheck($this->repo, ['DEPENDENCY_AGE_EXCEPTIONS' => "Security-Exception-Label: security-exception\n"]);

    expect($unlabelled->getExitCode())->toBe(1)
        ->and($unlabelled->getOutput().$unlabelled->getErrorOutput())->toContain("{$named} changed")
        ->and($labelled->getExitCode())->toBe(0, $labelled->getOutput().$labelled->getErrorOutput())
        ->and($labelled->getOutput().$labelled->getErrorOutput())->toContain("{$named} changed — allowed by the security-exception label");
})->with([
    'config.audit' => ['billing/composer.json', fn (string $json) => json_encode([...json_decode($json, true), 'config' => ['sort-packages' => true, 'audit' => ['ignore' => ['GHSA-xxxx']]]]), 'billing/composer.json: config.audit'],
    'repositories' => ['billing/composer.json', fn (string $json) => json_encode([...json_decode($json, true), 'repositories' => ['packagist.org' => false]]), 'billing/composer.json: repositories'],
    '.npmrc registry' => ['billing/.npmrc', fn (string $rc) => $rc."registry=https://npm.example.test/\n", 'billing/.npmrc: registry'],
    // Re-review of #539: npm's ini parser drops the quotes, so these are honoured; and userconfig loads another file.
    '.npmrc quoted registry' => ['billing/.npmrc', fn (string $rc) => $rc."\"registry\"=https://evil-quoted.invalid/\n", 'billing/.npmrc: registry'],
    '.npmrc quoted omit' => ['billing/.npmrc', fn (string $rc) => $rc."  'omit' = dev\n", 'billing/.npmrc: omit'],
    // Second re-review: npm's ini splits on CR alone, so these keys were invisible to a CRLF/LF-only split.
    '.npmrc CR-only line ends' => ['billing/.npmrc', fn (string $rc) => "save-exact=true\rhttps-proxy=http://127.0.0.1:9/\rstrict-ssl=false\rmin-release-age=7\n", 'billing/.npmrc: https-proxy'],
    '.npmrc userconfig' => ['billing/.npmrc', fn (string $rc) => $rc."userconfig=./evil.ini\n", 'billing/.npmrc: userconfig'],
    '.npmrc ca[]' => ['billing/.npmrc', fn (string $rc) => $rc."ca[]=-----BEGIN CERTIFICATE-----\n", 'billing/.npmrc: ca'],
    'composer config.policy' => ['billing/composer.json', fn (string $json) => json_encode([...json_decode($json, true), 'config' => ['sort-packages' => true, 'policy' => ['advisories' => ['audit' => 'ignore']]]]), 'billing/composer.json: config.policy'],
    'composer config.disable-tls' => ['billing/composer.json', fn (string $json) => json_encode([...json_decode($json, true), 'config' => ['sort-packages' => true, 'disable-tls' => true]]), 'billing/composer.json: config.disable-tls'],
]);

it('passes when composer.json and .npmrc change only elsewhere', function () {
    $this->repo = dckRepo();
    dckWrite($this->repo, 'billing/composer.json', json_encode(['config' => ['sort-packages' => true], 'name' => 'x/app', 'description' => 'reordered and described']));
    dckWrite($this->repo, 'billing/.npmrc', "# exact pins\nsave-exact=true\nmin-release-age=7\n");
    dckGit($this->repo, 'commit', '-qam', 'cosmetic');

    $run = dckCheck($this->repo);

    expect($run->getExitCode())->toBe(0, $run->getOutput().$run->getErrorOutput())
        ->and($run->getOutput())->toContain('Dependency policy: OK');
});
