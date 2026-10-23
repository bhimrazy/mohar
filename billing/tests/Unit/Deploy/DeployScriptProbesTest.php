<?php

/*
 * Review board probes for PR #111 (D3, head 729d1952): deploy/bin/deploy run against a fake `docker` that records
 * which services run, so the start/stop ordering, a failure midway and the rollback can be checked without Docker.
 * The fake fails `up` for the service named in FAKE_FAIL_UP, a probe of /up when FAKE_PROBE_RC=1 and a Caddy reload
 * when FAKE_RELOAD_RC=1, and answers Caddy's /config/ with the upstream file the script wrote.
 *
 * The first and the last two tests pass at 729d1952 and guard what works. The second and third fail at 729d1952: a
 * deploy that fails while moving the workers (after the switch) leaves deploy.env naming the old colour as live, so
 * `rollback` keeps traffic on the new colour, and neither a scheduler nor an automation worker runs.
 */

use Symfony\Component\Process\Process;

const D3_FAKE_DOCKER = <<<'BASH'
#!/usr/bin/env bash
dir="$FAKE_DIR"
echo "$*" >> "$dir/calls"
args=("$@")
[ "${args[0]}" = compose ] || exit 0
i=1 sub=""
while [ "$i" -lt "${#args[@]}" ]; do
    case "${args[$i]}" in
        -p|-f|--env-file|--profile) i=$((i + 2)) ;;
        *) sub="${args[$i]}"; i=$((i + 1)); break ;;
    esac
done
rest=("${args[@]:$i}")
touch "$dir/running"
check() {
    local s a
    s=$(grep -c '^scheduler-' "$dir/running")
    a=$(grep -c '^worker-automation-' "$dir/running")
    { [ "$s" -le 1 ] && [ "$a" -le 1 ]; } || echo "two at once: $(tr '\n' ' ' < "$dir/running")" >> "$dir/violations"
}
case "$sub" in
    up)
        skip=0
        for a in "${rest[@]}"; do
            if [ "$skip" = 1 ]; then skip=0; continue; fi
            case "$a" in
                --wait-timeout) skip=1 ;;
                -*) ;;
                *)
                    if [ "$a" = "${FAKE_FAIL_UP:-}" ]; then echo "fake: $a is unhealthy" >&2; exit 1; fi
                    grep -qx "$a" "$dir/running" || echo "$a" >> "$dir/running"
                    check
                    # Started, but `up --wait` reports it unhealthy: the container keeps running.
                    if [ "$a" = "${FAKE_UNHEALTHY_UP:-}" ]; then echo "fake: $a started but is unhealthy" >&2; exit 1; fi
                    ;;
            esac
        done
        ;;
    stop)
        for a in "${rest[@]}"; do
            if [ "$a" = "${FAKE_FAIL_STOP:-}" ]; then echo "fake: stopping $a failed" >&2; exit 1; fi
            grep -vx -- "$a" "$dir/running" > "$dir/running.tmp"; mv "$dir/running.tmp" "$dir/running"
        done
        ;;
    ps)
        case " ${rest[*]} " in *" caddy "*) grep -qx caddy "$dir/running" && echo caddy-id ;; esac
        ;;
    exec)
        case " ${rest[*]} " in
            *" reload "*) exit "${FAKE_RELOAD_RC:-0}" ;;
            *"2019/config"*) if [ "${FAKE_CONFIG_STALE:-0}" = 1 ]; then echo '{"stale":true}'; else cat "$DEPLOY_STATE_DIR/live/upstream.caddy"; fi ;;
            *"/up"*) exit "${FAKE_PROBE_RC:-0}" ;;
        esac
        ;;
    run) exit "${FAKE_RUN_RC:-0}" ;;
esac
exit 0
BASH;

function d3Sandbox(): string
{
    $dir = sys_get_temp_dir().'/d3-probe-'.bin2hex(random_bytes(4));
    mkdir("{$dir}/bin", 0700, true);
    file_put_contents("{$dir}/bin/docker", D3_FAKE_DOCKER);
    chmod("{$dir}/bin/docker", 0755);
    file_put_contents("{$dir}/env", "APP_DOMAIN=billing.example.com\n");

    return $dir;
}

/** Run deploy/bin/deploy in the sandbox; returns the exit code. */
function d3Deploy(string $dir, array $args = [], array $env = []): int
{
    $process = new Process(['bash', dirname(__DIR__, 4).'/deploy/bin/deploy', ...$args], null, [
        'PATH' => "{$dir}/bin:".getenv('PATH'),
        'FAKE_DIR' => $dir,
        'DEPLOY_ENV_FILE' => "{$dir}/env",
        'DEPLOY_STATE_DIR' => "{$dir}/state",
        'DEPLOY_PROJECT' => 'probe',
        ...$env,
    ]);
    $process->setTimeout(60)->run();

    return (int) $process->getExitCode();
}

/** @return list<string> */
function d3Running(string $dir): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents("{$dir}/running"))));
}

function d3Upstream(string $dir): string
{
    preg_match('/reverse_proxy (app-\w+):8080/', (string) @file_get_contents("{$dir}/state/live/upstream.caddy"), $m);

    return $m[1] ?? 'none';
}

afterEach(function () {
    if (isset($this->sandbox)) {
        (new Process(['rm', '-rf', $this->sandbox]))->run();
    }
});

test('a deploy fills the fiscal snapshots once the workers have moved', function () {
    $this->sandbox = $dir = d3Sandbox();

    expect(d3Deploy($dir, ['--image', 'img:1']))->toBe(0);
    $calls = array_values(array_filter(explode("\n", (string) file_get_contents("{$dir}/calls"))));
    $fill = array_keys(array_filter($calls, fn (string $call): bool => str_contains($call, 'billing:fiscal-snapshot')));
    $workers = array_keys(array_filter($calls, fn (string $call): bool => str_contains($call, 'worker-automation-blue')));

    expect($fill)->toHaveCount(1)->and($workers)->not->toBeEmpty()->and($fill[0])->toBeGreaterThan(max($workers));
});

test('deploys and rollbacks never run two schedulers or two automation workers, and the live colour runs both', function () {
    $this->sandbox = $dir = d3Sandbox();

    foreach ([['--image', 'img:1'], ['--image', 'img:2'], ['rollback'], ['rollback'], ['--image', 'img:3']] as $i => $args) {
        expect(d3Deploy($dir, $args))->toBe(0, 'step '.$i);
        $live = d3Upstream($dir) === 'app-blue' ? 'blue' : 'green';
        expect(d3Running($dir))->toContain("scheduler-{$live}", "worker-automation-{$live}", "worker-default-{$live}")
            ->not->toContain('scheduler-'.($live === 'blue' ? 'green' : 'blue'));
    }

    expect(@file_get_contents("{$dir}/violations"))->toBeFalse();
});

/** Deploy blue, then green, then a third deploy (to blue) in which $failing does not become healthy. */
function d3FailedMove(string $dir, string $failing = 'worker-automation-blue'): void
{
    expect(d3Deploy($dir, ['--image', 'img:1']))->toBe(0); // blue
    expect(d3Deploy($dir, ['--image', 'img:2']))->toBe(0); // green
    expect(d3Deploy($dir, ['--image', 'img:3'], ['FAKE_FAIL_UP' => $failing]))->not->toBe(0);
    expect(d3Upstream($dir))->toBe('app-blue'); // traffic had already switched
}

test('after a deploy that fails while moving the workers, rollback returns to the release that was live before', function () {
    $this->sandbox = $dir = d3Sandbox();
    d3FailedMove($dir);

    expect(d3Deploy($dir, ['rollback']))->toBe(0);
    expect(d3Upstream($dir))->toBe('app-green');
});

test('a new scheduler that does not become healthy leaves the old one running instead of none', function () {
    $this->sandbox = $dir = d3Sandbox();
    // `up --wait` fails when the new scheduler is unhealthy (a crash loop, say); the old one was already stopped.
    d3FailedMove($dir, 'scheduler-blue');

    expect(array_values(array_filter(d3Running($dir), fn (string $s) => str_starts_with($s, 'scheduler-'))))->toBe(['scheduler-green']);
});

test('an unhealthy new colour is stopped and traffic, workers and state stay on the live colour', function () {
    $this->sandbox = $dir = d3Sandbox();
    expect(d3Deploy($dir, ['--image', 'img:1']))->toBe(0); // blue
    $state = file_get_contents("{$dir}/state/deploy.env");

    expect(d3Deploy($dir, ['--image', 'img:2'], ['FAKE_FAIL_UP' => 'app-green']))->not->toBe(0);

    expect(d3Upstream($dir))->toBe('app-blue')
        ->and(d3Running($dir))->not->toContain('app-green')->toContain('scheduler-blue', 'worker-automation-blue')
        ->and(file_get_contents("{$dir}/state/deploy.env"))->toBe($state);
});

test('a Caddy reload that fails restores the previous upstream file', function () {
    $this->sandbox = $dir = d3Sandbox();
    expect(d3Deploy($dir, ['--image', 'img:1']))->toBe(0); // blue

    expect(d3Deploy($dir, ['--image', 'img:2'], ['FAKE_RELOAD_RC' => '1']))->not->toBe(0);

    expect(d3Upstream($dir))->toBe('app-blue')
        ->and(d3Running($dir))->toContain('scheduler-blue')->not->toContain('scheduler-green');
});

test('a reload whose new upstream never shows in Caddy\'s config restores the previous upstream and the state', function () {
    $this->sandbox = $dir = d3Sandbox();
    expect(d3Deploy($dir, ['--image', 'img:1']))->toBe(0); // blue
    $state = file_get_contents("{$dir}/state/deploy.env");

    expect(d3Deploy($dir, ['--image', 'img:2'], ['FAKE_CONFIG_STALE' => '1']))->not->toBe(0);

    expect(d3Upstream($dir))->toBe('app-blue')
        ->and(file_get_contents("{$dir}/state/deploy.env"))->toBe($state)
        ->and(d3Running($dir))->toContain('scheduler-blue', 'worker-automation-blue')->not->toContain('scheduler-green');
});

// Re-check at 1a65bc6c: the recovery path in move_workers. When the new scheduler started but is unhealthy and
// stopping it fails, `stop ... || true` carries on and starts the old scheduler beside it.
test('the recovery from an unhealthy new scheduler never starts the old one while the new one may still run', function () {
    $this->sandbox = $dir = d3Sandbox();
    expect(d3Deploy($dir, ['--image', 'img:1']))->toBe(0); // blue
    expect(d3Deploy($dir, ['--image', 'img:2']))->toBe(0); // green

    expect(d3Deploy($dir, ['--image', 'img:3'], ['FAKE_UNHEALTHY_UP' => 'scheduler-blue', 'FAKE_FAIL_STOP' => 'scheduler-blue']))->not->toBe(0);

    expect(@file_get_contents("{$dir}/violations"))->toBeFalse();
});

test('the recovery from an unhealthy new scheduler that stops cleanly runs the old one again', function () {
    $this->sandbox = $dir = d3Sandbox();
    expect(d3Deploy($dir, ['--image', 'img:1']))->toBe(0); // blue
    expect(d3Deploy($dir, ['--image', 'img:2']))->toBe(0); // green

    expect(d3Deploy($dir, ['--image', 'img:3'], ['FAKE_UNHEALTHY_UP' => 'scheduler-blue']))->not->toBe(0);

    $running = d3Running($dir);
    expect(array_values(array_filter($running, fn (string $s) => str_starts_with($s, 'scheduler-'))))->toBe(['scheduler-green'])
        ->and(array_values(array_filter($running, fn (string $s) => str_starts_with($s, 'worker-automation-'))))->toBe(['worker-automation-green'])
        ->and(@file_get_contents("{$dir}/violations"))->toBeFalse();
});
