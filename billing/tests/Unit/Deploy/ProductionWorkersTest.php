<?php

/*
 * Review board probes for PR #80: the worker pools, the scheduler and their health checks in
 * deploy/compose.prod.yaml, checked against the code and config they run (ADR 0014, config/queue.php,
 * deploy/php/app.ini). The first two reproduce review findings; the rest guard what is correct today.
 * If the worker health checks move to a heartbeat (the better fix), replace the first test with one for it.
 */

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

function d2DeployDir(): string
{
    return dirname(__DIR__, 4).'/deploy';
}

function d2Services(): array
{
    return Yaml::parseFile(d2DeployDir().'/compose.prod.yaml')['services'];
}

/** @return array<string, list<string>> pool => queues */
function d2Pools(): array
{
    $pools = [];

    foreach (d2Services() as $name => $service) {
        $command = $service['command'] ?? [];
        if (is_array($command) && in_array('queue:work', $command, true)) {
            $queue = collect($command)->first(fn (string $arg) => str_starts_with($arg, '--queue='));
            $pools[$name] = explode(',', substr((string) $queue, strlen('--queue=')));
        }
    }

    return $pools;
}

function d2Option(array $command, string $name): ?int
{
    $arg = collect($command)->first(fn (string $arg) => str_starts_with($arg, "--{$name}="));

    return $arg === null ? null : (int) substr($arg, strlen("--{$name}="));
}

function d2Seconds(string $duration): int
{
    preg_match('/^(\d+)(s|m)$/', $duration, $m) || throw new RuntimeException("unparsed duration {$duration}");

    return (int) $m[1] * ($m[2] === 'm' ? 60 : 1);
}

function d2Megabytes(string $size): int
{
    preg_match('/^(\d+)([MG])$/i', $size, $m) || throw new RuntimeException("unparsed size {$size}");

    return (int) $m[1] * (strtoupper($m[2]) === 'G' ? 1024 : 1);
}

/**
 * Runs a service's CMD-SHELL health check against a fake /proc holding the given command lines, as the
 * container would see them: PID 1 is always docker-init (compose `init: true`) carrying the full command.
 *
 * @param  array<int, string>  $processes  pid => command line (space separated)
 */
function d2HealthCheckPasses(string $service, array $processes): bool
{
    $test = d2Services()[$service]['healthcheck']['test'];
    expect($test[0])->toBe('CMD-SHELL');

    $proc = sys_get_temp_dir().'/pr80-proc-'.bin2hex(random_bytes(4));
    foreach ($processes as $pid => $cmdline) {
        mkdir("{$proc}/{$pid}", 0700, true);
        file_put_contents("{$proc}/{$pid}/cmdline", str_replace(' ', "\0", $cmdline)."\0");
    }

    // Compose turns $$ into $ before the container sees the command.
    $script = str_replace(['$$', '/proc/'], ['$', "{$proc}/"], $test[1]);
    exec('sh -c '.escapeshellarg($script).' 2>/dev/null', $output, $status);
    (new Filesystem)->deleteDirectory($proc);

    return $status === 0;
}

test('a worker health check fails when only docker-init (PID 1) still carries the queue:work command', function () {
    $init = '/sbin/docker-init -- app-entrypoint php artisan queue:work --queue=default --tries=3';

    foreach (array_keys(d2Pools()) as $pool) {
        expect(d2HealthCheckPasses($pool, [1 => $init, 7 => 'php artisan queue:work --queue=default']))
            ->toBeTrue("{$pool}: a live worker must pass")
            ->and(d2HealthCheckPasses($pool, [1 => $init, 9 => 'sleep 300']))
            ->toBeFalse("{$pool}: the check matches docker-init's own command line, so it can never fail");
    }

    expect(d2HealthCheckPasses('scheduler', [1 => '/sbin/docker-init -- app-entrypoint php artisan schedule:work']))
        ->toBeFalse('scheduler: the check matches docker-init, so it can never fail');
});

test('the scheduler memory limit leaves room for a child process at the PHP memory_limit', function () {
    // schedule:work forks schedule:run, which runs automation:run (the daily billing pipeline) inline: three PHP
    // processes in one cgroup, the last allowed up to memory_limit.
    preg_match('/^memory_limit\s*=\s*(\d+)M/m', file_get_contents(d2DeployDir().'/php/app.ini'), $m);
    $phpLimit = (int) $m[1];
    $containerLimit = d2Megabytes(d2Services()['scheduler']['deploy']['resources']['limits']['memory']);

    expect($containerLimit)->toBeGreaterThanOrEqual($phpLimit + 128);
});

test('every queue the code dispatches onto is consumed by exactly one pool', function () {
    $dispatched = ['default'];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app'));
    foreach ($files as $file) {
        if ($file->getExtension() === 'php'
            && preg_match_all("/onQueue\\('([a-z_-]+)'\\)/", file_get_contents($file->getPathname()), $m)) {
            array_push($dispatched, ...$m[1]);
        }
    }

    $consumed = array_merge(...array_values(d2Pools()));

    expect(array_diff(array_unique($dispatched), $consumed))->toBe([])
        ->and(array_unique($consumed))->toHaveCount(count($consumed))
        ->and(array_keys(d2Pools()))->toEqualCanonicalizing(['worker-critical', 'worker-notifications', 'worker-default']);
});

test('pool timeouts stay below retry_after and each stop grace covers the pool timeout', function () {
    $retryAfter = (require dirname(__DIR__, 3).'/config/queue.php')['connections']['database']['retry_after'];

    foreach (d2Pools() as $pool => $queues) {
        $service = d2Services()[$pool];
        $timeout = d2Option($service['command'], 'timeout');

        expect($timeout)->not->toBeNull()
            ->and($timeout)->toBeLessThan($retryAfter)
            ->and(d2Seconds($service['stop_grace_period']))->toBeGreaterThan($timeout);
    }

    // ADR 0014: the critical pool covers the provisioning cap (180 s) plus its 20 s lock slack.
    expect(d2Option(d2Services()['worker-critical']['command'], 'timeout'))->toBeGreaterThanOrEqual(200);
});
