<?php

/*
 * scripts/security-exceptions.sh (infra security review 2026-10-31, M2): the cooldown waivers count only while the PR
 * body is unchanged since the security-exception label was added. A stub `gh` answers the GraphQL call by applying the
 * script's own --jq filter (with jq) to a canned response, so the filter is tested too; nothing reaches GitHub.
 */

use Symfony\Component\Process\Process;

/**
 * @param  array<string, mixed>|null  $pullRequest  the GraphQL pullRequest object; null makes gh fail
 * @param  list<string>  $args
 */
function sexRun(?array $pullRequest, array $args = ['534', 'owner/mohar']): Process
{
    $dir = realpath(sys_get_temp_dir()).'/sex-'.bin2hex(random_bytes(4));
    mkdir("{$dir}/bin", 0700, true);
    file_put_contents("{$dir}/response.json", json_encode(['data' => ['repository' => ['pullRequest' => $pullRequest]]]));
    file_put_contents("{$dir}/bin/gh", <<<'SH'
#!/bin/bash
echo "$*" > "$SEX_DIR/args"
[ -n "${SEX_FAIL:-}" ] && { echo 'HTTP 502' >&2; exit 1; }
while [ $# -gt 0 ]; do [ "$1" = --jq ] && { jq -r "$2" < "$SEX_DIR/response.json"; exit $?; }; shift; done
exit 3
SH);
    chmod("{$dir}/bin/gh", 0755);

    $process = new Process(['bash', dirname(__DIR__, 4).'/scripts/security-exceptions.sh', ...$args], $dir, [
        'PATH' => "{$dir}/bin:".getenv('PATH'),
        'SEX_DIR' => $dir,
        'SEX_FAIL' => $pullRequest === null ? '1' : '',
    ]);
    $process->setTimeout(30)->run();
    (new Process(['rm', '-rf', $dir]))->run();

    return $process;
}

/** @param  list<array{0: string, 1: string}>  $labelled  [label, createdAt] events, oldest first */
function sexPr(array $labels, array $labelled, ?string $lastEditedAt, string $body): array
{
    return [
        'body' => $body,
        'lastEditedAt' => $lastEditedAt,
        'labels' => ['nodes' => array_map(fn (string $name) => ['name' => $name], $labels)],
        'timelineItems' => ['nodes' => array_map(fn (array $e) => ['createdAt' => $e[1], 'label' => ['name' => $e[0]]], $labelled)],
    ];
}

const SEX_BODY = "Bumps a package.\n\nSecurity-Exception: symfony/mime@8.1.10\n  security-exception: left-pad@1.3.0\nNot a Security-Exception: line\n";

beforeEach(function () {
    (new Process(['jq', '--version']))->run() === 0 || $this->markTestSkipped('jq is not installed');
});

it('prints nothing without the label, whatever the body says', function () {
    $run = sexRun(sexPr(['ci'], [['ci', '2026-10-31T10:00:00Z']], null, SEX_BODY));

    expect($run->getExitCode())->toBe(0)->and($run->getOutput())->toBe('');
});

it('prints the label marker and the Security-Exception lines when the body was never edited or only before the label', function () {
    $never = sexRun(sexPr(['security-exception'], [['security-exception', '2026-10-31T10:00:00Z']], null, SEX_BODY));
    $before = sexRun(sexPr(['security-exception'], [['security-exception', '2026-10-31T10:00:00Z']], '2026-10-31T09:59:59Z', SEX_BODY));

    $lines = "Security-Exception-Label: security-exception\nSecurity-Exception: symfony/mime@8.1.10\n  security-exception: left-pad@1.3.0\n";
    expect($never->getExitCode())->toBe(0, $never->getErrorOutput())->and($never->getOutput())->toBe($lines)
        ->and($before->getExitCode())->toBe(0)->and($before->getOutput())->toBe($lines);
});

it('fails when the body was edited after the label, until the label is added again', function () {
    $events = [['security-exception', '2026-10-30T10:00:00Z']];
    $edited = sexRun(sexPr(['security-exception'], $events, '2026-10-31T08:00:00Z', SEX_BODY));
    // Removed and re-added after the edit: the latest labelled event counts, not the first or another label's.
    $relabelled = sexRun(sexPr(['security-exception'], [...$events, ['security-exception', '2026-10-31T09:00:00Z'], ['ci', '2026-10-31T09:30:00Z']], '2026-10-31T08:00:00Z', SEX_BODY));
    $otherLabelLater = sexRun(sexPr(['security-exception'], [...$events, ['ci', '2026-10-31T09:00:00Z']], '2026-10-31T08:00:00Z', SEX_BODY));
    // An edit in the same second as the label cannot be shown to come before it.
    $sameSecond = sexRun(sexPr(['security-exception'], $events, '2026-10-30T10:00:00Z', SEX_BODY));

    expect($edited->getExitCode())->toBe(1)->and($edited->getOutput())->toBe('')
        ->and($edited->getErrorOutput())->toContain('edited (2026-10-31T08:00:00Z) after, or in the same second as, the security-exception label was added (2026-10-30T10:00:00Z)')
        ->and($relabelled->getExitCode())->toBe(0)->and($relabelled->getOutput())->toContain('symfony/mime@8.1.10')
        ->and($otherLabelLater->getExitCode())->toBe(1)
        ->and($sameSecond->getExitCode())->toBe(1)->and($sameSecond->getOutput())->toBe('');
});

it('fails closed when the label is present but its event is not found, or GitHub cannot be read', function () {
    $noEvent = sexRun(sexPr(['security-exception'], [], null, SEX_BODY));
    $ghDown = sexRun(null);

    expect($noEvent->getExitCode())->toBe(1)->and($noEvent->getErrorOutput())->toContain('not found')
        ->and($ghDown->getExitCode())->toBe(2)->and($ghDown->getErrorOutput())->toContain('could not read PR #534 of owner/mohar');
});

it('refuses arguments that are not a PR number and an owner/repo', function (array $args) {
    $run = sexRun(sexPr([], [], null, ''), $args);

    expect($run->getExitCode())->toBe(2)->and($run->getErrorOutput())->toContain('usage:');
})->with([
    'no arguments' => [[]],
    'not a number' => [['53a', 'owner/mohar']],
    'no repository' => [['534']],
    'not owner/repo' => [['534', 'owner/mohar/x']],
    'an option' => [['534', '--jq=.']],
]);
