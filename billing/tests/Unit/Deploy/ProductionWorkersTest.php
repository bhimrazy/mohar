<?php

/*
 * Review board probes for PR #80: the worker pools, the scheduler and their health checks in
 * deploy/compose.prod.yaml, checked against the code and config they run (ADR 0014, config/queue.php,
 * deploy/php/app.ini, App\Support\Queues). Round 1 (6088b033): the first two reproduce findings. Round 3 (4cbb2be9):
 * updated for worker-automation and the 60 s default timeout, plus a probe that the scheduler's stop grace covers the
 * daily automation run (fails at 4cbb2be9). If the worker health checks move to a heartbeat (the better fix), replace
 * the first test with one for it.
 */

use App\Support\Queues;
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

/** The connection a queue:work command reads: its positional argument, else the default (database). */
function d2Connection(array $command): string
{
    $position = array_search('queue:work', $command, true);
    $argument = $command[$position + 1] ?? null;

    return is_string($argument) && ! str_starts_with($argument, '--') ? $argument : 'database';
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
    $dispatched = ['default', Queues::AUTOMATION];
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
        ->and(array_keys(d2Pools()))->toEqualCanonicalizing(['worker-critical', 'worker-notifications', 'worker-default', 'worker-automation']);
});

test('the worker commands are Queues::workerCommands(), plus the hourly --max-time on the three pools', function () {
    $commands = [];
    foreach (d2Pools() as $pool => $queues) {
        $command = d2Services()[$pool]['command'];
        $commands[] = implode(' ', array_values(array_filter($command, fn (string $arg) => $arg !== '--max-time=3600')));
        expect(in_array('--max-time=3600', $command, true))->toBe($pool !== 'worker-automation', "{$pool}: --max-time");
    }

    expect($commands)->toEqualCanonicalizing(Queues::workerCommands());
});

test('pool timeouts stay below their connection retry_after and each stop grace covers the pool timeout', function () {
    $config = require dirname(__DIR__, 3).'/config/queue.php';

    foreach (d2Pools() as $pool => $queues) {
        $service = d2Services()[$pool];
        // queue:work's default --timeout is 60 s.
        $timeout = d2Option($service['command'], 'timeout') ?? 60;
        $retryAfter = $config['connections'][d2Connection($service['command'])]['retry_after'];

        expect($timeout)->toBeLessThan($retryAfter, "{$pool}: timeout {$timeout} s vs retry_after {$retryAfter} s")
            ->and(d2Seconds($service['stop_grace_period']))->toBeGreaterThan($timeout, "{$pool}: stop grace");
    }

    // ADR 0014: the critical pool covers the provisioning cap (180 s) plus its 20 s lock slack.
    expect(d2Option(d2Services()['worker-critical']['command'], 'timeout'))->toBeGreaterThanOrEqual(Queues::PROVIDER_POOL_TIMEOUT_SECONDS)
        ->and(d2Connection(d2Services()['worker-automation']['command']))->toBe(Queues::AUTOMATION)
        ->and($config['connections'][Queues::AUTOMATION]['retry_after'])->toBe(Queues::AUTOMATION_RETRY_AFTER_SECONDS);
});

test('a deploy during the daily automation run lets the scheduler finish it instead of killing it', function () {
    // schedule:work traps SIGTERM and waits for its schedule:run children; the daily automation:run runs inline in
    // one of them and holds the automation:run lock for up to AUTOMATION_TIMEOUT_SECONDS. A shorter stop grace
    // SIGKILLs the billing pipeline mid-run on every `up -d` that recreates the scheduler, and leaves its
    // withoutOverlapping mutex (24 h) and the command lock (1 h) behind. An idle scheduler still stops in ~0.1 s.
    expect(d2Seconds(d2Services()['scheduler']['stop_grace_period']))
        ->toBeGreaterThan(Queues::AUTOMATION_TIMEOUT_SECONDS);
});

test('an import job stops before the default pool would kill it, and the pool may use the memory one needs', function () {
    $command = d2Services()['worker-default']['command'];

    expect(Queues::IMPORT_JOB_TIMEOUT_SECONDS)->toBeLessThan(Queues::DEFAULT_POOL_TIMEOUT_SECONDS)
        ->and(d2Option($command, 'timeout'))->toBe(Queues::DEFAULT_POOL_TIMEOUT_SECONDS)
        ->and(d2Option($command, 'memory'))->toBe(Queues::DEFAULT_POOL_MEMORY_MB)
        ->and(in_array(Queues::IMPORTS, d2Pools()['worker-default'], true))->toBeTrue();
});
