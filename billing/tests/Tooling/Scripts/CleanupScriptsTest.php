<?php

/*
 * scripts/finish-workstream.sh, scripts/sweep-stale.sh, scripts/pg.sh --drop, the worktree step of
 * scripts/storage-cleanup.sh and the slug check of scripts/new-worktree.sh in a throwaway repository, with stub
 * `gh` and `docker` programs first on PATH: nothing here touches GitHub, Docker or the real repository.
 * The stub docker logs every call to $CCS_LOG and answers `psql` with the rows in $CCS_ROWS.
 */

use Symfony\Component\Process\Process;

function ccsGit(string $dir, string ...$args): string
{
    $process = new Process(['git', '-c', 'user.name=test', '-c', 'user.email=test@localhost', '-c', 'commit.gpgsign=false', ...$args], $dir);
    $process->setTimeout(30)->mustRun();

    return trim($process->getOutput());
}

/** @return array{root: string, main: string, bin: string, log: string, rows: string} */
function ccsSetup(): array
{
    $root = realpath(sys_get_temp_dir()).'/ccs-'.bin2hex(random_bytes(4));
    $main = "{$root}/main";
    $bin = "{$root}/bin";
    mkdir($bin, 0700, true);
    mkdir("{$main}/scripts", 0700, true);
    foreach (['finish-workstream.sh', 'sweep-stale.sh', 'pg.sh', 'storage-cleanup.sh', 'new-worktree.sh'] as $script) {
        copy(__DIR__."/../../../../scripts/{$script}", "{$main}/scripts/{$script}");
        chmod("{$main}/scripts/{$script}", 0755);
    }
    file_put_contents("{$main}/.gitignore", ".claude/worktrees/\n.claude-in-use\n");
    file_put_contents("{$bin}/gh", "#!/bin/bash\nif [ -n \"\${CCS_GH_FAIL:-}\" ]; then echo 'HTTP 401 bad credentials' >&2; exit 1; fi\ncase \"\$*\" in *'--json headRefOid'*) printf '%s' \"\${CCS_MERGED_HEADS:-}\" ;; *) printf '%s' \"\${CCS_MERGED_LINES:-}\" ;; esac\n");
    file_put_contents("{$bin}/docker", <<<'SH'
#!/bin/bash
echo "$*" >> "$CCS_LOG"
case "$1" in
    ps) [ -n "${CCS_PG_UP:-}" ] && echo mohar-pg ;;
    exec) case "$*" in *psql*) cat "$CCS_ROWS" ;; esac ;;
esac
exit 0
SH);
    chmod("{$bin}/gh", 0755);
    chmod("{$bin}/docker", 0755);
    touch("{$root}/docker.log");
    touch("{$root}/rows");

    ccsGit($root, 'init', '-q', '--bare', '-b', 'develop', 'origin.git');
    ccsGit($root, 'init', '-q', '-b', 'develop', 'main');
    ccsGit($main, 'remote', 'add', 'origin', "{$root}/origin.git");
    ccsGit($main, 'add', '-A');
    ccsGit($main, 'commit', '-qm', 'base');
    ccsGit($main, 'push', '-q', 'origin', 'develop');
    ccsGit($main, 'fetch', '-q', 'origin');

    return ['root' => $root, 'main' => $main, 'bin' => $bin, 'log' => "{$root}/docker.log", 'rows' => "{$root}/rows"];
}

/** @param  array<string, string>  $env */
function ccsRun(array $ctx, string $script, array $args = [], array $env = [], ?string $cwd = null): Process
{
    $process = new Process(['/bin/bash', "{$ctx['main']}/scripts/{$script}", ...$args], $cwd ?? $ctx['root'], [
        'PATH' => $ctx['bin'].':'.getenv('PATH'),
        'CCS_LOG' => $ctx['log'],
        'CCS_ROWS' => $ctx['rows'],
        'GATE_REPO' => 'o/r',
        'SWEEP_IDLE_HOURS' => '0',
        ...$env,
    ]);
    $process->setTimeout(60)->run();

    return $process;
}

function ccsWorktree(array $ctx, string $slug, bool $detached = false, bool $commit = false): string
{
    $path = "{$ctx['main']}/.claude/worktrees/{$slug}";
    $detached
        ? ccsGit($ctx['main'], 'worktree', 'add', '-q', '--detach', $path, 'origin/develop')
        : ccsGit($ctx['main'], 'worktree', 'add', '-q', '--no-track', '-b', "feature/{$slug}", $path, 'origin/develop');
    if ($commit) {
        ccsGit($path, 'commit', '-q', '--allow-empty', '-m', 'local work');
    }

    return $path;
}

function ccsCleanup(array $ctx): void
{
    (new Process(['rm', '-rf', $ctx['root']]))->run();
}

test('finish refuses a detached worktree with local commits and changes nothing', function () {
    $ctx = ccsSetup();
    $path = ccsWorktree($ctx, 'rb-detached', detached: true, commit: true);
    $sha = ccsGit($path, 'rev-parse', 'HEAD');

    $run = ccsRun($ctx, 'finish-workstream.sh', ['rb-detached']);

    expect($run->getExitCode())->toBe(2)
        ->and($run->getErrorOutput())->toContain('not on origin')
        ->and(is_dir($path))->toBeTrue()
        ->and(ccsGit($ctx['main'], 'cat-file', '-t', $sha))->toBe('commit');
    ccsCleanup($ctx);
});

test('finish refuses an unpushed branch before removing the worktree or the branch', function () {
    $ctx = ccsSetup();
    $path = ccsWorktree($ctx, 'rb-ahead', commit: true);

    $run = ccsRun($ctx, 'finish-workstream.sh', ['rb-ahead']);

    expect($run->getExitCode())->toBe(2)
        ->and(is_dir($path))->toBeTrue()
        ->and(ccsGit($ctx['main'], 'branch', '--list', 'feature/rb-ahead'))->toContain('feature/rb-ahead');
    ccsCleanup($ctx);
});

test('finish reports a gh failure instead of calling the head unmerged, and stays safe', function () {
    $ctx = ccsSetup();
    $path = ccsWorktree($ctx, 'rb-ghfail', commit: true);

    $run = ccsRun($ctx, 'finish-workstream.sh', ['rb-ghfail'], ['CCS_GH_FAIL' => '1']);

    expect($run->getExitCode())->toBe(2)
        ->and($run->getErrorOutput())->toContain('gh failed')->toContain('bad credentials')
        ->and(is_dir($path))->toBeTrue();
    ccsCleanup($ctx);
});

test('finish removes the worktree and branch when the head is a merged PR head', function () {
    $ctx = ccsSetup();
    $path = ccsWorktree($ctx, 'rb-merged', commit: true);
    $sha = ccsGit($path, 'rev-parse', 'HEAD');

    $run = ccsRun($ctx, 'finish-workstream.sh', ['rb-merged'], ['CCS_MERGED_HEADS' => $sha."\n"]);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and(is_dir($path))->toBeFalse()
        ->and(ccsGit($ctx['main'], 'branch', '--list', 'feature/rb-merged'))->toBe('');
    ccsCleanup($ctx);
});

test('finish removes a detached worktree whose commits are all on origin, and a mistyped slug changes nothing', function () {
    $ctx = ccsSetup();
    $path = ccsWorktree($ctx, 'rb-clean', detached: true);
    $other = ccsWorktree($ctx, 'rb-other');

    $typo = ccsRun($ctx, 'finish-workstream.sh', ['rb-clena']);
    $run = ccsRun($ctx, 'finish-workstream.sh', ['rb-clean'], cwd: $path);

    expect($typo->getExitCode())->toBe(0)
        ->and(is_dir($other))->toBeTrue()
        ->and($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and(is_dir($path))->toBeFalse();
    ccsCleanup($ctx);
});

test('finish with --dry-run after the slug changes nothing', function () {
    $ctx = ccsSetup();
    $path = ccsWorktree($ctx, 'rb-dry', commit: true);
    $sha = ccsGit($path, 'rev-parse', 'HEAD');

    $run = ccsRun($ctx, 'finish-workstream.sh', ['rb-dry', '--dry-run'], ['CCS_MERGED_HEADS' => $sha."\n", 'CCS_PG_UP' => '1']);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($run->getOutput())->toContain('would: remove worktree')
        ->and(is_dir($path))->toBeTrue()
        ->and(ccsGit($ctx['main'], 'branch', '--list', 'feature/rb-dry'))->toContain('feature/rb-dry')
        ->and(file_get_contents($ctx['log']))->not->toContain('dropdb');
    ccsCleanup($ctx);
});

test('finish refuses an unknown option and never takes it for the slug', function () {
    $ctx = ccsSetup();
    $path = ccsWorktree($ctx, 'rb-flag');

    $before = ccsRun($ctx, 'finish-workstream.sh', ['--force', 'rb-flag']);
    $after = ccsRun($ctx, 'finish-workstream.sh', ['rb-flag', '--dryrun']);

    expect($before->getExitCode())->toBe(1)->and($before->getErrorOutput())->toContain("unknown option '--force'")
        ->and($after->getExitCode())->toBe(1)
        ->and(is_dir($path))->toBeTrue();
    ccsCleanup($ctx);
});

test('finish refuses a tree marked with .claude-in-use, even when its work is merged', function () {
    $ctx = ccsSetup();
    $path = ccsWorktree($ctx, 'rb-marked', commit: true);
    $sha = ccsGit($path, 'rev-parse', 'HEAD');
    touch("{$path}/.claude-in-use");

    $run = ccsRun($ctx, 'finish-workstream.sh', ['rb-marked'], ['CCS_MERGED_HEADS' => $sha."\n"]);

    expect($run->getExitCode())->toBe(1)->and($run->getErrorOutput())->toContain('marked in use')
        ->and(is_dir($path))->toBeTrue()
        ->and(ccsGit($ctx['main'], 'branch', '--list', 'feature/rb-marked'))->toContain('feature/rb-marked');
    ccsCleanup($ctx);
});

test('finish refuses a pushed branch worktree whose PR is not merged, and changes nothing', function () {
    $ctx = ccsSetup();
    $path = ccsWorktree($ctx, 'rb-open', commit: true);
    ccsGit($path, 'push', '-q', 'origin', 'feature/rb-open');
    ccsGit($ctx['main'], 'fetch', '-q', 'origin');

    $run = ccsRun($ctx, 'finish-workstream.sh', ['rb-open']);

    expect($run->getExitCode())->toBe(2)
        ->and($run->getErrorOutput())->toContain('not the head of a merged PR')
        ->and(is_dir($path))->toBeTrue()
        ->and(ccsGit($ctx['main'], 'branch', '--list', 'feature/rb-open'))->toContain('feature/rb-open');
    ccsCleanup($ctx);
});

test('finish refuses a dirty worktree', function () {
    $ctx = ccsSetup();
    $path = ccsWorktree($ctx, 'rb-dirty');
    file_put_contents("{$path}/scratch.txt", 'x');

    $run = ccsRun($ctx, 'finish-workstream.sh', ['rb-dirty']);

    expect($run->getExitCode())->toBe(1)->and(is_dir($path))->toBeTrue();
    ccsCleanup($ctx);
});

test('pg.sh --drop rejects a slug that looks like a parallel copy, and a missing slug prints the usage', function () {
    $ctx = ccsSetup();

    $bad = ccsRun($ctx, 'pg.sh', ['--drop', 'rb533-test-2-x'], ['CCS_PG_UP' => '1']);
    $none = ccsRun($ctx, 'pg.sh', ['--drop', '--dry-run'], ['CCS_PG_UP' => '1']);

    expect($bad->getExitCode())->not->toBe(0)->and($bad->getErrorOutput())->toContain('test_<n>')
        ->and($none->getExitCode())->toBe(1)->and($none->getErrorOutput())->toContain('usage: pg.sh --drop [--dry-run] <slug>');
    ccsCleanup($ctx);
});

test('pg.sh --drop never drops with --dry-run in any position, and excludes the databases of other worktrees', function () {
    $ctx = ccsSetup();
    ccsWorktree($ctx, 'rb533-other');
    file_put_contents($ctx['rows'], "billing_test_rb533|f\nbilling_test_rb533_test_1|f\n");

    $run = ccsRun($ctx, 'pg.sh', ['--drop', 'rb533', '--dry-run'], ['CCS_PG_UP' => '1']);
    $log = file_get_contents($ctx['log']);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($run->getOutput())->toContain('would drop billing_test_rb533')
        ->and($log)->not->toContain('dropdb')
        ->and($log)->toContain("NOT (datname = 'billing_test_rb533_other'")
        ->and($log)->toContain('^billing_test_rb533(_test_[0-9]+)?(_[A-Za-z0-9_]+)?$');

    $real = ccsRun($ctx, 'pg.sh', ['--drop', 'rb533'], ['CCS_PG_UP' => '1']);
    expect($real->getExitCode())->toBe(0)->and(file_get_contents($ctx['log']))->toContain('dropdb -U billing --if-exists billing_test_rb533_test_1');
    ccsCleanup($ctx);
});

test('sweep keeps a marked tree, an active tree and a dirty tree, and removes an idle merged one only with --apply', function () {
    $ctx = ccsSetup();
    $marked = ccsWorktree($ctx, 'rb-marked');
    touch("{$marked}/.claude-in-use");
    $dirty = ccsWorktree($ctx, 'rb-dirty');
    file_put_contents("{$dirty}/x.txt", 'x');
    $review = ccsWorktree($ctx, '_review/rb-review', detached: true);
    $merged = ccsWorktree($ctx, 'rb-merged');
    $sha = ccsGit($merged, 'rev-parse', 'HEAD');
    $lines = "feature/rb-merged {$sha}\n";

    $dry = ccsRun($ctx, 'sweep-stale.sh', [], ['CCS_MERGED_LINES' => $lines]);
    expect($dry->getExitCode())->toBe(0, $dry->getErrorOutput())
        ->and($dry->getOutput())->toContain('kept: '.$marked.': marked in use')->toContain('kept: '.$dirty.': uncommitted')
        ->and($dry->getOutput())->toContain('would remove: '.$merged)
        ->and(is_dir($merged))->toBeTrue();

    $active = ccsRun($ctx, 'sweep-stale.sh', ['--apply'], ['CCS_MERGED_LINES' => $lines, 'SWEEP_IDLE_HOURS' => '12']);
    expect($active->getOutput())->toContain('kept: '.$merged.': active in the last 12 h')->and(is_dir($merged))->toBeTrue();

    $apply = ccsRun($ctx, 'sweep-stale.sh', ['--apply'], ['CCS_MERGED_LINES' => $lines]);
    expect($apply->getExitCode())->toBe(0, $apply->getErrorOutput())
        ->and(is_dir($merged))->toBeFalse()->and(is_dir($marked))->toBeTrue()->and(is_dir($dirty))->toBeTrue()
        ->and(is_dir($review))->toBeFalse()
        ->and(ccsGit($ctx['main'], 'branch', '--list', 'feature/rb-merged'))->toBe('');
    ccsCleanup($ctx);
});

test('sweep lists orphan databases but drops them only with --apply --dbs', function () {
    $ctx = ccsSetup();
    file_put_contents($ctx['rows'], "billing_test_rb_orphan\n");
    $env = ['CCS_PG_UP' => '1'];

    $apply = ccsRun($ctx, 'sweep-stale.sh', ['--apply'], $env);
    expect($apply->getOutput())->toContain('would drop billing_test_rb_orphan')->and(file_get_contents($ctx['log']))->not->toContain('dropdb');

    $dbs = ccsRun($ctx, 'sweep-stale.sh', ['--apply', '--dbs'], $env);
    expect($dbs->getOutput())->toContain('dropped billing_test_rb_orphan')->and(file_get_contents($ctx['log']))->toContain('dropdb');
    ccsCleanup($ctx);
});

test('storage cleanup keeps a merged worktree that is marked or recently active, and removes an idle one only with --apply', function () {
    $ctx = ccsSetup();
    $env = ['CCS_MERGED_LINES' => '42', 'CODESPACES' => false, 'STORAGE_DOCKER' => false, 'LOW_SPACE_GB' => '0'];
    $paths = [];
    foreach (['sc-marked', 'sc-active', 'sc-idle'] as $slug) {
        $paths[$slug] = ccsWorktree($ctx, $slug, commit: true);
        ccsGit($paths[$slug], 'push', '-q', 'origin', "feature/{$slug}");
        ccsGit($paths[$slug], 'fetch', '-q', 'origin');
    }
    touch("{$paths['sc-marked']}/.claude-in-use");

    // SWEEP_IDLE_HOURS=12: every tree was just created, so all three count as active.
    $active = ccsRun($ctx, 'storage-cleanup.sh', ['--apply'], [...$env, 'SWEEP_IDLE_HOURS' => '12']);
    $dry = ccsRun($ctx, 'storage-cleanup.sh', [], $env);

    expect($active->getExitCode())->toBe(0, $active->getErrorOutput())
        ->and($active->getOutput())->toContain("{$paths['sc-marked']}: keep (marked in use (.claude-in-use))")
        ->and($active->getOutput())->toContain("{$paths['sc-idle']}: keep (active in the last 12 h)")
        ->and(is_dir($paths['sc-idle']))->toBeTrue()
        ->and($dry->getOutput())->toContain("{$paths['sc-marked']}: keep (marked in use (.claude-in-use))")
        ->and($dry->getOutput())->toContain("{$paths['sc-idle']}: PR #42 merged, clean, pushed -> remove")
        ->and(is_dir($paths['sc-idle']))->toBeTrue();

    $apply = ccsRun($ctx, 'storage-cleanup.sh', ['--apply'], $env);

    expect($apply->getExitCode())->toBe(0, $apply->getErrorOutput())
        ->and(is_dir($paths['sc-idle']))->toBeFalse()
        ->and(is_dir($paths['sc-active']))->toBeFalse()
        ->and(is_dir($paths['sc-marked']))->toBeTrue();
    ccsCleanup($ctx);
});

test('new-worktree refuses a slug that is not a plain name before touching git', function () {
    $ctx = ccsSetup();

    foreach (['', '../escape', '-x', 'a/b', 'a b'] as $slug) {
        $run = ccsRun($ctx, 'new-worktree.sh', [$slug]);

        expect($run->getExitCode())->toBe(2, "slug '{$slug}'")
            ->and($run->getErrorOutput())->toContain('usage: scripts/new-worktree.sh <slug>');
    }
    expect(ccsGit($ctx['main'], 'worktree', 'list', '--porcelain'))->not->toContain('.claude/worktrees')
        ->and(ccsGit($ctx['main'], 'branch', '--list', 'feature/*'))->toBe('');
    ccsCleanup($ctx);
});
