<?php

/*
 * The workflow rules the infra security review (2026-10-31) verified or asked for, pinned so they cannot drift:
 * least permissions, no pull_request_target, no persisted credentials, no `${{ }}` inside a `run:` block (M1: the
 * checkers that judge a PR are the base branch's copies; M2: waivers through scripts/security-exceptions.sh; M3: the
 * weekly audit installs nothing; L5: every directory with a third-party compose image is watched by Dependabot).
 */

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
            ->and($triggers)->not->toContain('pull_request_target', $file)
            ->and($triggers)->not->toContain('workflow_run', $file);

        foreach (wfSteps($workflow) as $step) {
            $label = $step['name'] ?? $step['uses'] ?? '?';
            if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')) {
                expect($step['with']['persist-credentials'] ?? null)->toBeFalse("{$file}: {$label}");
            }
            expect((string) ($step['run'] ?? ''))->not->toContain('${{', "{$file}: {$label} puts an expression in shell");
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

    foreach (['/.github/', '/scripts/', '/billing/scripts/', '/deploy/', '/billing/docs/devops/dependency-policy.md', '/billing/docs/adr/0007-dependency-pinning-and-cooldown.md'] as $path) {
        expect($owners)->toMatch('#^'.preg_quote($path, '#').' @bhimrazy$#m');
    }
});
