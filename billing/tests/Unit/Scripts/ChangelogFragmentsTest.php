<?php

/*
 * ADR 0027: scripts/changelog-assemble.sh and scripts/changelog-check.sh, run against throwaway repositories that hold
 * a small copy of the two changelogs' layout.
 */

use Symfony\Component\Process\Process;

const CLF_APP_LOG = <<<'MD'
# Changelog

Intro.

## [Unreleased]

### Fixed

- **Old fix.**

### Topic heading (#1)

- Legacy entry.

## [0.1.0]

### Added

- **Released.**

MD;

const CLF_KERNEL_LOG = <<<'MD'
# Kernel API changelog

Intro.

## Unreleased (pre-1.0)

### Old entry (#2)

- Old bullet.

MD;

function clfScripts(): string
{
    return dirname(__DIR__, 4).'/scripts';
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $env
 */
function clfRun(string $dir, array $command, array $env = []): Process
{
    $process = new Process($command, $dir, ['GITHUB_ACTIONS' => '', 'BASE_REF' => 'develop', 'PR_NUMBER' => '', ...$env]);
    $process->setTimeout(60)->run();

    return $process;
}

function clfGit(string $dir, string ...$args): void
{
    $process = clfRun($dir, ['git', '-c', 'user.name=test', '-c', 'user.email=test@localhost', '-c', 'commit.gpgsign=false', ...$args]);
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
}

/** A repository with both changelogs committed on `main`. */
function clfRepo(): string
{
    $dir = sys_get_temp_dir().'/changelog-fragments-'.bin2hex(random_bytes(4));
    clfWrite($dir, 'billing/changelog.md', CLF_APP_LOG);
    clfWrite($dir, 'billing/docs/architecture/kernel-changelog.md', CLF_KERNEL_LOG);
    clfWrite($dir, 'billing/changelog.d/README.md', "# Fragments\n");
    clfWrite($dir, 'billing/app/Modules/Foo.php', "<?php\n");
    clfGit($dir, 'init', '-q', '-b', 'main');
    clfGit($dir, 'add', '-A');
    clfGit($dir, 'commit', '-q', '-m', 'base');

    return $dir;
}

function clfWrite(string $dir, string $path, string $content): void
{
    @mkdir(dirname("{$dir}/{$path}"), 0700, true);
    file_put_contents("{$dir}/{$path}", $content);
}

function clfAssemble(string $dir, string ...$args): Process
{
    return clfRun($dir, ['bash', clfScripts().'/changelog-assemble.sh', '--root', $dir, ...$args]);
}

/**
 * Commits the working tree and runs the check on main..HEAD.
 *
 * @param  array<string, string>  $env
 */
function clfCheck(string $dir, string $message = 'change', array $env = []): Process
{
    clfGit($dir, 'add', '-A');
    clfGit($dir, 'commit', '-q', '--allow-empty', '-m', $message);

    return clfRun($dir, ['bash', clfScripts().'/changelog-check.sh', 'main', 'HEAD'], $env);
}

afterEach(function () {
    if (isset($this->repo)) {
        (new Process(['rm', '-rf', $this->repo]))->run();
    }
});

describe('changelog-assemble.sh', function () {
    it('changes nothing when there are no fragments', function () {
        $this->repo = clfRepo();

        $run = clfAssemble($this->repo);

        expect($run->getExitCode())->toBe(0)
            ->and($run->getOutput())->toContain('no fragments')
            ->and(file_get_contents("{$this->repo}/billing/changelog.md"))->toBe(CLF_APP_LOG)
            ->and(file_get_contents("{$this->repo}/billing/docs/architecture/kernel-changelog.md"))->toBe(CLF_KERNEL_LOG)
            ->and(file_exists("{$this->repo}/billing/changelog.d/README.md"))->toBeTrue();
    });

    it('puts each section under Unreleased in file-name order, creates missing sections and deletes the fragments', function () {
        $this->repo = clfRepo();
        clfWrite($this->repo, 'billing/changelog.d/b-branch.md', "### Fixed\n\n- **B fix.**\n  Continued.\n\n### Security\n\n- **B security.**\n");
        clfWrite($this->repo, 'billing/changelog.d/412.md', "### Added\n\n- **412 added.**\n\n### Fixed\n\n- **412 fix.**\n");
        clfWrite($this->repo, 'billing/changelog.d/a-branch.md', "### Fixed\n\n- **A fix.**\n");

        $run = clfAssemble($this->repo);

        expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
            ->and(file_get_contents("{$this->repo}/billing/changelog.md"))->toBe(<<<'MD'
                # Changelog

                Intro.

                ## [Unreleased]

                ### Added

                - **412 added.**

                ### Security

                - **B security.**

                ### Fixed

                - **412 fix.**
                - **A fix.**
                - **B fix.**
                  Continued.

                - **Old fix.**

                ### Topic heading (#1)

                - Legacy entry.

                ## [0.1.0]

                ### Added

                - **Released.**

                MD)
            ->and(glob("{$this->repo}/billing/changelog.d/*.md"))->toBe(["{$this->repo}/billing/changelog.d/README.md"]);
    });

    it('puts kernel entries above the existing ones in file-name order', function () {
        $this->repo = clfRepo();
        clfWrite($this->repo, 'billing/docs/architecture/kernel-changelog.d/zeta.md', "### `Zeta` (#20)\n\n- Zeta bullet.\n");
        clfWrite($this->repo, 'billing/docs/architecture/kernel-changelog.d/alpha.md', "### `Alpha` (#30)\n\n- Alpha bullet.\n  More.\n");

        $run = clfAssemble($this->repo);

        expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
            ->and(file_get_contents("{$this->repo}/billing/docs/architecture/kernel-changelog.md"))->toBe(<<<'MD'
                # Kernel API changelog

                Intro.

                ## Unreleased (pre-1.0)

                ### `Alpha` (#30)

                - Alpha bullet.
                  More.

                ### `Zeta` (#20)

                - Zeta bullet.

                ### Old entry (#2)

                - Old bullet.

                MD)
            ->and(file_get_contents("{$this->repo}/billing/changelog.md"))->toBe(CLF_APP_LOG)
            ->and(glob("{$this->repo}/billing/docs/architecture/kernel-changelog.d/*.md"))->toBe([]);
    });

    it('refuses a malformed fragment and writes nothing', function (string $name, string $content, string $message) {
        $this->repo = clfRepo();
        clfWrite($this->repo, 'billing/changelog.d/good.md', "### Fixed\n\n- **Good.**\n");
        clfWrite($this->repo, "billing/changelog.d/{$name}", $content);

        $run = clfAssemble($this->repo);

        expect($run->getExitCode())->toBe(1)
            ->and($run->getErrorOutput())->toContain($message)->toContain('nothing changed')
            ->and(file_get_contents("{$this->repo}/billing/changelog.md"))->toBe(CLF_APP_LOG)
            ->and(file_exists("{$this->repo}/billing/changelog.d/good.md"))->toBeTrue();
    })->with([
        'unknown section' => ['bad.md', "### Removed\n\n- x\n", '"### Removed" is not a section'],
        'section twice' => ['bad.md', "### Fixed\n\n- x\n\n### Fixed\n\n- y\n", 'section Fixed appears twice'],
        'empty section' => ['bad.md', "### Added\n\n### Fixed\n\n- y\n", 'section Added has no entry'],
        'no section' => ['bad.md', "- a bullet\n", 'text before the first section heading'],
        'prose, not a bullet' => ['bad.md', "### Fixed\n\nSome prose.\n", 'must start with a bullet'],
        'other heading' => ['bad.md', "## [Unreleased]\n\n### Fixed\n\n- x\n", '"## [Unreleased]" is not a section'],
        'bad name' => ['Bad Name.md', "### Fixed\n\n- x\n", 'name it <branch-slug>.md'],
    ]);

    it('refuses a kernel fragment without exactly one heading', function () {
        $this->repo = clfRepo();
        clfWrite($this->repo, 'billing/docs/architecture/kernel-changelog.d/two.md', "### One (#1)\n\n- x\n\n### Two (#2)\n\n- y\n");

        $run = clfAssemble($this->repo, '--check');

        expect($run->getExitCode())->toBe(1)
            ->and($run->getErrorOutput())->toContain('one "### <title> (#<pr>)" heading per fragment');
    });

    it('previews without changing anything', function () {
        $this->repo = clfRepo();
        clfWrite($this->repo, 'billing/changelog.d/a.md', "### Fixed\n\n- **A fix.**\n");

        $run = clfAssemble($this->repo, '--preview');

        expect($run->getExitCode())->toBe(0)
            ->and($run->getOutput())->toContain("billing/changelog.md, ### Fixed:\n\n- **A fix.**")
            ->and(file_get_contents("{$this->repo}/billing/changelog.md"))->toBe(CLF_APP_LOG)
            ->and(file_exists("{$this->repo}/billing/changelog.d/a.md"))->toBeTrue();
    });
});

describe('changelog-check.sh', function () {
    it('fails a direct edit of either changelog and points to the fragment folder', function (string $path, string $folder) {
        $this->repo = clfRepo();
        clfGit($this->repo, 'checkout', '-q', '-b', 'feature/x');
        file_put_contents("{$this->repo}/{$path}", "\n- edited\n", FILE_APPEND);

        $run = clfCheck($this->repo);

        expect($run->getExitCode())->toBe(1)
            ->and($run->getOutput())->toContain("do not edit {$path} directly; add {$folder}/<branch-slug>.md");
    })->with([
        ['billing/changelog.md', 'billing/changelog.d'],
        ['billing/docs/architecture/kernel-changelog.md', 'billing/docs/architecture/kernel-changelog.d'],
    ]);

    it('passes a PR that adds a fragment', function () {
        $this->repo = clfRepo();
        clfGit($this->repo, 'checkout', '-q', '-b', 'feature/x');
        clfWrite($this->repo, 'billing/app/Modules/Foo.php', "<?php\n// changed\n");
        clfWrite($this->repo, 'billing/changelog.d/x.md', "### Fixed\n\n- **X.**\n");

        $run = clfCheck($this->repo);

        expect($run->getExitCode())->toBe(0, $run->getOutput())
            ->and($run->getOutput())->not->toContain('warning');
    });

    it('fails a malformed fragment', function () {
        $this->repo = clfRepo();
        clfGit($this->repo, 'checkout', '-q', '-b', 'feature/x');
        clfWrite($this->repo, 'billing/changelog.d/x.md', "### Fixes\n\n- **X.**\n");

        $run = clfCheck($this->repo);

        expect($run->getExitCode())->toBe(1)
            ->and($run->getErrorOutput())->toContain('"### Fixes" is not a section');
    });

    it('passes the assemble commit', function () {
        $this->repo = clfRepo();
        clfWrite($this->repo, 'billing/changelog.d/x.md', "### Fixed\n\n- **X.**\n");
        clfWrite($this->repo, 'billing/docs/architecture/kernel-changelog.d/y.md', "### Y (#5)\n\n- Y.\n");
        clfGit($this->repo, 'add', '-A');
        clfGit($this->repo, 'commit', '-q', '-m', 'fragments');
        clfGit($this->repo, 'checkout', '-q', '-b', 'chore/assemble');
        expect(clfAssemble($this->repo)->getExitCode())->toBe(0);

        $run = clfCheck($this->repo, "docs(changelog): assemble fragments\n\nChangelog-Assemble: yes");

        expect($run->getExitCode())->toBe(0, $run->getOutput())
            ->and($run->getOutput())->toContain('assemble commit');
    });

    it('fails a commit with the marker that also changes other files', function () {
        $this->repo = clfRepo();
        clfGit($this->repo, 'checkout', '-q', '-b', 'chore/assemble');
        file_put_contents("{$this->repo}/billing/changelog.md", "\n- edited\n", FILE_APPEND);
        clfWrite($this->repo, 'billing/app/Modules/Foo.php', "<?php\n// changed\n");

        $run = clfCheck($this->repo, "x\n\nChangelog-Assemble: yes");

        expect($run->getExitCode())->toBe(1)
            ->and($run->getOutput())->toContain('also changes: billing/app/Modules/Foo.php');
    });

    it('reads the marker from --marker-rev, the PR head inside CI\'s merge commit', function () {
        $this->repo = clfRepo();
        clfGit($this->repo, 'checkout', '-q', '-b', 'chore/assemble');
        file_put_contents("{$this->repo}/billing/changelog.md", "\n- assembled\n", FILE_APPEND);
        clfGit($this->repo, 'add', '-A');
        clfGit($this->repo, 'commit', '-q', '-m', "assemble\n\nChangelog-Assemble: yes");
        clfGit($this->repo, 'checkout', '-q', 'main');
        clfGit($this->repo, 'merge', '-q', '--no-ff', '--no-edit', 'chore/assemble');

        $merge = clfRun($this->repo, ['bash', clfScripts().'/changelog-check.sh', 'HEAD^1', 'HEAD']);
        $head = clfRun($this->repo, ['bash', clfScripts().'/changelog-check.sh', '--marker-rev', 'HEAD^2', 'HEAD^1', 'HEAD']);

        expect($merge->getExitCode())->toBe(1)
            ->and($head->getExitCode())->toBe(0, $head->getOutput());
    });

    it('lets a PR opened before fragments keep its direct edit, with a warning', function () {
        $this->repo = clfRepo();
        clfGit($this->repo, 'checkout', '-q', '-b', 'feature/old');
        file_put_contents("{$this->repo}/billing/changelog.md", "\n- edited\n", FILE_APPEND);

        $old = clfCheck($this->repo, env: ['PR_NUMBER' => '393']);
        $new = clfRun($this->repo, ['bash', clfScripts().'/changelog-check.sh', 'main', 'HEAD'], ['PR_NUMBER' => '394']);

        expect($old->getExitCode())->toBe(0)
            ->and($old->getOutput())->toContain('warning: billing/changelog.md: PR #393 predates changelog fragments')
            ->and($new->getExitCode())->toBe(1);
    });

    it('skips release PRs into main', function () {
        $this->repo = clfRepo();
        clfGit($this->repo, 'checkout', '-q', '-b', 'develop');
        file_put_contents("{$this->repo}/billing/changelog.md", "\n- edited\n", FILE_APPEND);

        $run = clfCheck($this->repo, env: ['BASE_REF' => 'main']);

        expect($run->getExitCode())->toBe(0)->and($run->getOutput())->toContain('release PR into main');
    });

    it('warns, without failing, when app or kernel code changes with no fragment', function () {
        $this->repo = clfRepo();
        clfGit($this->repo, 'checkout', '-q', '-b', 'feature/x');
        clfWrite($this->repo, 'billing/app/Contracts/Thing.php', "<?php\n");

        $run = clfCheck($this->repo, env: ['GITHUB_ACTIONS' => 'true']);

        expect($run->getExitCode())->toBe(0)
            ->and($run->getOutput())->toContain('::warning file=billing/changelog.d::app code changed but no billing/changelog.d/ fragment')
            ->toContain('::warning file=billing/docs/architecture/kernel-changelog.d::the kernel surface changed');
    });

    it('stays quiet for a docs-only change', function () {
        $this->repo = clfRepo();
        clfGit($this->repo, 'checkout', '-q', '-b', 'feature/docs');
        clfWrite($this->repo, 'docs/note.md', "# Note\n");

        $run = clfCheck($this->repo);

        expect($run->getExitCode())->toBe(0)->and($run->getOutput())->not->toContain('warning');
    });
});

it('runs the changelog check in CI and in the local merge gate, which stands in for CI', function () {
    $root = dirname(__DIR__, 4);
    $ci = (string) file_get_contents("{$root}/.github/workflows/ci.yml");
    $gate = (string) file_get_contents("{$root}/scripts/merge-gate.sh");

    expect($ci)->toContain('../scripts/changelog-check.sh --marker-rev HEAD^2 HEAD^1 HEAD')
        ->and($gate)->toContain('changelog-check.sh" --marker-rev HEAD^2 HEAD^1 HEAD');
});
