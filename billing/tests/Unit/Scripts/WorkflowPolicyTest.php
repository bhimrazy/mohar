<?php

/*
 * The workflow rules the infra security review (2026-10-31) verified or asked for, pinned so they cannot drift:
 * least permissions, no pull_request_target, no persisted credentials, no `${{ }}` inside a `run:` block (M1: the
 * checkers that judge a PR are the base branch's copies; M2: waivers through scripts/security-exceptions.sh; M3: the
 * weekly audit installs nothing; L5: every directory with a third-party compose image is watched by Dependabot).
 */

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function wfRoot(): string
{
    return dirname(__DIR__, 4);
}

/** @return array<string, array<string, mixed>> parsed workflows by file name */
function wfWorkflows(): array
{
    $workflows = [];
    foreach (glob(wfRoot().'/.github/workflows/*.yml') as $path) {
        $workflows[basename($path)] = Yaml::parseFile($path);
    }

    return $workflows;
}

/** @return list<array<string, mixed>> every step of a workflow */
function wfSteps(array $workflow): array
{
    return array_merge(...array_map(fn (array $job) => $job['steps'] ?? [], array_values($workflow['jobs'])));
}

test('every workflow reads by default, never uses pull_request_target, never persists credentials and keeps expressions out of shell', function () {
    expect(wfWorkflows())->not->toBeEmpty();

    foreach (wfWorkflows() as $file => $workflow) {
        $triggers = array_keys((array) ($workflow['on'] ?? []));

        expect($workflow['permissions'] ?? null)->toBe(['contents' => 'read'], $file)
            // Pest's toContain() takes extra needles, not a message: these use in_array/str_contains to keep one.
            ->and(in_array('pull_request_target', $triggers, true))->toBeFalse("{$file} uses pull_request_target")
            ->and(in_array('workflow_run', $triggers, true))->toBeFalse("{$file} uses workflow_run");

        foreach (wfSteps($workflow) as $step) {
            $label = $step['name'] ?? $step['uses'] ?? '?';
            if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')) {
                expect($step['with']['persist-credentials'] ?? null)->toBeFalse("{$file}: {$label}");
            }
            expect(str_contains((string) ($step['run'] ?? ''), '${{'))->toBeFalse("{$file}: {$label} puts an expression in shell");
        }
    }
});

test('CI runs the base branch\'s changelog, waiver and dependency checkers on the PR\'s tree', function () {
    $raw = (string) file_get_contents(wfRoot().'/.github/workflows/ci.yml');
    $steps = collect(wfSteps(Yaml::parseFile(wfRoot().'/.github/workflows/ci.yml')));
    $names = $steps->pluck('name')->all();
    $policy = (string) $steps->firstWhere('name', 'Policy scripts from the base branch')['run'];

    expect($policy)->toContain('if [ "$EVENT" = pull_request ]; then rev=HEAD^1; fi')
        ->and($policy)->toContain('git -C .. archive "$rev" billing/scripts | tar -x -C .ci-policy -f -')
        ->and($policy)->toContain('for f in changelog-check.sh changelog-assemble.sh security-exceptions.sh; do')
        ->and($raw)->toContain('run: .ci-policy/changelog-check.sh --marker-rev HEAD^2 HEAD^1 HEAD')
        ->and($raw)->toContain('.ci-policy/security-exceptions.sh "$PR_NUMBER" "$REPOSITORY"')
        ->and($raw)->toContain('node .ci-policy/check-dependency-age.mjs --all')
        ->and($raw)->toContain('node .ci-policy/check-dependency-age.mjs --base="$BASE"')
        // No path back to the PR's own copies or to an unbound read of the PR body.
        ->and($raw)->not->toMatch('#node scripts/check-dependency-age\.mjs|\.\./scripts/changelog-check\.sh|--json body#');

    $at = fn (string $name) => array_search($name, $names, true);
    expect($at('Policy scripts from the base branch'))->toBeLessThan($at('Changelog fragments'))
        ->and($at('Dependency cooldown and pinning (before install)'))->toBeLessThan($at('Remove the policy scripts'))
        ->and($at('Remove the policy scripts'))->toBeLessThan($at('Install dependencies'));
});

test('every audit names its registry, dependency types and abandoned rule on the command line', function () {
    $files = ['.github/workflows/ci.yml', '.github/workflows/dependency-audit.yml', 'scripts/merge-gate.sh', 'billing/composer.json'];
    foreach ($files as $file) {
        $text = (string) file_get_contents(wfRoot()."/{$file}");
        preg_match_all('/npm audit\b[^\n"\')]*/', $text, $npm);
        preg_match_all('/composer audit\b[^\n"&]*/', $text, $composer);
        $npm = array_filter($npm[0], fn (string $call) => str_contains($call, '--package-lock-only'));
        $composer = array_filter($composer[0], fn (string $call) => str_contains($call, '--locked'));

        expect($npm)->not->toBeEmpty($file)->and($composer)->not->toBeEmpty($file);
        foreach ($npm as $call) {
            expect(str_contains($call, '--registry=https://registry.npmjs.org/ --include=dev'))->toBeTrue("{$file}: {$call}");
        }
        foreach ($composer as $call) {
            expect(str_contains($call, '--abandoned=report'))->toBeTrue("{$file}: {$call}");
        }
    }
});

test('a push run takes the waivers of the PR it merged, and only of that PR', function () {
    $raw = (string) file_get_contents(wfRoot().'/.github/workflows/ci.yml');

    expect($raw)->toContain('PR_NUMBER=$(gh api "repos/$REPOSITORY/commits/$SHA/pulls"')
        ->and($raw)->toContain('select(.merged_at != null and .merge_commit_sha == \"$SHA\")')
        ->and($raw)->toContain('SHA: ${{ github.sha }}');
});

test('the merge gate reads waivers through the same script as CI', function () {
    $gate = (string) file_get_contents(wfRoot().'/scripts/merge-gate.sh');

    expect($gate)->toContain('"$repo/scripts/security-exceptions.sh" "$pr" "$gh_repo"')
        ->and($gate)->not->toContain('--json body');
});

test('the weekly dependency audit installs nothing and checks every item', function () {
    $workflow = Yaml::parseFile(wfRoot().'/.github/workflows/dependency-audit.yml');
    $steps = wfSteps($workflow);
    $runs = implode("\n", array_map(fn (array $step) => (string) ($step['run'] ?? ''), $steps));
    $uses = array_values(array_filter(array_map(fn (array $step) => isset($step['uses']) ? explode('@', $step['uses'])[0] : null, $steps)));

    expect(array_keys($workflow['on']))->toBe(['schedule', 'workflow_dispatch'])
        ->and($uses)->toBe(['actions/checkout', 'shivammathur/setup-php', 'actions/setup-node'])
        ->and($runs)->toContain('composer audit --locked')
        ->and(substr_count($runs, 'npm audit --audit-level=high --package-lock-only'))->toBe(2)
        ->and($runs)->toContain('node scripts/check-dependency-age.mjs --all --min-days=0')
        ->and($runs)->not->toMatch('/\b(npm (ci|install|i)|composer (install|update|require)|npx)\b/');
});

test('Dependabot watches every directory that holds a third-party compose image', function () {
    $config = Yaml::parseFile(wfRoot().'/.github/dependabot.yml');
    $compose = collect($config['updates'])->firstWhere('package-ecosystem', 'docker-compose');
    $watched = $compose['directories'] ?? [$compose['directory']];

    $needed = [];
    foreach (['billing/compose.yaml', ...glob(wfRoot().'/{deploy,loadtest}/compose*.yaml', GLOB_BRACE)] as $path) {
        $path = str_starts_with($path, '/') ? $path : wfRoot().'/'.$path;
        $hasThirdParty = preg_match('/^\s*image:\s*(?!\$\{)\S+@sha256:/m', (string) file_get_contents($path));
        if ($hasThirdParty) {
            $needed[] = '/'.basename(dirname($path));
        }
    }

    expect(array_values(array_unique($needed)))->toEqualCanonicalizing(['/billing', '/deploy', '/loadtest'])
        ->and(array_diff($needed, $watched))->toBe([])
        // One PR per image across the directories: the postgres pin must move in billing/ and deploy/ together.
        ->and($compose['groups']['compose-images']['group-by'] ?? null)->toBe('dependency-name');
});

test('CODEOWNERS covers what judges and ships a change', function () {
    $owners = (string) file_get_contents(wfRoot().'/.github/CODEOWNERS');

    foreach (['/.github/', '/scripts/', '/billing/scripts/', '/deploy/', '/billing/docs/devops/dependency-policy.md', '/billing/docs/adr/0007-dependency-pinning-and-cooldown.md', '/billing/composer.json', '/billing/package.json', '/billing/.npmrc'] as $path) {
        expect($owners)->toMatch('#^'.preg_quote($path, '#').' @bhimrazy$#m');
    }
});

/**
 * Runs ci.yml's "Policy scripts from the base branch" step, as written, in billing/ of a repository whose HEAD merges
 * a PR branch into $base (PR #539 review, B2: a PR into main, whose tree has no billing/scripts, failed the check).
 *
 * @param  array<string, string>  $baseFiles  files on the base branch
 * @param  array<string, string>  $prFiles  files the PR branch adds or changes
 * @return array{0: Process, 1: string}
 */
function wfRunPolicyStep(array $baseFiles, array $prFiles): array
{
    $dir = realpath(sys_get_temp_dir()).'/wfp-'.bin2hex(random_bytes(4));
    $git = function (string ...$args) use ($dir): void {
        (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@l', '-c', 'commit.gpgsign=false', ...$args], $dir))->mustRun();
    };
    $write = function (array $files) use ($dir): void {
        foreach ($files as $path => $content) {
            @mkdir(dirname("{$dir}/{$path}"), 0700, true);
            file_put_contents("{$dir}/{$path}", $content);
        }
    };
    mkdir($dir, 0700, true);
    $git('init', '-q', '-b', 'main');
    $write(['readme.md' => "landing\n", ...$baseFiles]);
    $git('add', '-A');
    $git('commit', '-qm', 'base');
    $git('checkout', '-qb', 'pr');
    $write($prFiles);
    $git('add', '-A');
    $git('commit', '-qm', 'pr');
    $git('checkout', '-q', 'main');
    $git('merge', '-q', '--no-ff', '-m', 'merge', 'pr');

    $step = collect(wfSteps(Yaml::parseFile(wfRoot().'/.github/workflows/ci.yml')))->firstWhere('name', 'Policy scripts from the base branch');
    $run = new Process(['bash', '-eo', 'pipefail', '-c', $step['run']], "{$dir}/billing", ['EVENT' => 'pull_request']);
    $run->setTimeout(30)->run();

    return [$run, $dir];
}

$wfPrScripts = [
    'billing/scripts/check-dependency-age.mjs' => "// pr\n",
    'billing/scripts/dependency-policy.mjs' => "// pr\n",
    'billing/scripts/lib/helper.mjs' => "// pr helper\n",
    'scripts/changelog-check.sh' => "#!/bin/sh\n# pr\n",
    'scripts/changelog-assemble.sh' => "#!/bin/sh\n# pr\n",
    'scripts/security-exceptions.sh' => "#!/bin/sh\n# pr\n",
];

test('on a PR into a base without billing/scripts (main) the policy step takes the PR\'s copies with a warning', function () use ($wfPrScripts) {
    [$run, $dir] = wfRunPolicyStep([], $wfPrScripts);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($run->getOutput())->toContain('::warning file=billing/scripts/check-dependency-age.mjs::the base branch has no billing/scripts yet')
        ->and(file_get_contents("{$dir}/billing/.ci-policy/check-dependency-age.mjs"))->toBe("// pr\n")
        ->and(file_get_contents("{$dir}/billing/.ci-policy/lib/helper.mjs"))->toBe("// pr helper\n")
        ->and(is_executable("{$dir}/billing/.ci-policy/changelog-check.sh"))->toBeTrue();
    (new Process(['rm', '-rf', $dir]))->run();
});

test('on a PR into develop the policy step takes the base branch\'s whole scripts tree, not the PR\'s', function () use ($wfPrScripts) {
    $base = [
        'billing/scripts/check-dependency-age.mjs' => "// base\n",
        'billing/scripts/dependency-policy.mjs' => "// base\n",
        'billing/scripts/lib/helper.mjs' => "// base helper\n",
        'scripts/changelog-check.sh' => "#!/bin/sh\n# base\n",
        'scripts/changelog-assemble.sh' => "#!/bin/sh\n# base\n",
        'scripts/security-exceptions.sh' => "#!/bin/sh\n# base\n",
    ];
    [$run, $dir] = wfRunPolicyStep($base, $wfPrScripts);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($run->getOutput())->not->toContain('::warning')
        ->and(file_get_contents("{$dir}/billing/.ci-policy/check-dependency-age.mjs"))->toBe("// base\n")
        ->and(file_get_contents("{$dir}/billing/.ci-policy/lib/helper.mjs"))->toBe("// base helper\n")
        ->and(file_get_contents("{$dir}/billing/.ci-policy/changelog-check.sh"))->toBe("#!/bin/sh\n# base\n")
        ->and(is_dir("{$dir}/billing/.ci-policy/billing"))->toBeFalse();
    (new Process(['rm', '-rf', $dir]))->run();
});

test('CI and the merge gate never let the PR tree supply the dependency-age cache', function () {
    $raw = (string) file_get_contents(wfRoot().'/.github/workflows/ci.yml');
    $gate = (string) file_get_contents(wfRoot().'/scripts/merge-gate.sh');

    expect($raw)->toContain('DEPENDENCY_AGE_CACHE: ${{ runner.temp }}/dependency-age-${{ github.run_id }}.json')
        ->and($gate)->toContain('rm -f "$logs/dependency-age-cache.json"')
        ->and($gate)->toContain('env DEPENDENCY_AGE_CACHE="$logs/dependency-age-cache.json"');
});
