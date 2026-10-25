<?php

use Illuminate\Support\ConfigurationUrlParser;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Simulators\SimulatorHarness;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Review board probes for PR #115 (ADR 0016 S4) at cf492f1. Both fail at that head; each names the fix it expects.
 */
test('the harness judges the database the connection really opens, not the DB_DATABASE name beside a DB_URL', function (string $connection, string $name) {
    $url = 'pgsql://billing:billing@127.0.0.1:5432/billing';
    config([
        'database.default' => $connection,
        "database.connections.{$connection}.database" => $name,
        "database.connections.{$connection}.url" => $url,
    ]);

    // What Laravel will actually connect to: the URL wins over the `database` key.
    expect((new ConfigurationUrlParser)->parseConfiguration(config("database.connections.{$connection}"))['database'])->toBe('billing');

    $harness = new class
    {
        use SimulatorHarness;
    };

    try {
        // At cf492f1 the guard reads only config('database.connections.<default>.database'), so it lets this through
        // and the scenario's migrate:fresh would run on `billing`. Fix: check the resolved name (getDatabaseName()
        // on the resolved connection, or SELECT current_database() on PostgreSQL) and the driver.
        expect(fn () => $harness->startSimulators())->toThrow(RuntimeException::class, 'throwaway database');
    } finally {
        $harness->stopSimulators();
    }
})->with([
    'sqlite in memory, DB_URL to the development database' => ['sqlite', ':memory:'],
    'a billing_e2e_ name, DB_URL to the development database' => ['pgsql', 'billing_e2e_0123abcd'],
]);

test('simulate:e2e interrupted with Ctrl-C still removes its throwaway database', function () {
    // setsid: the runner leads its own process group, so the group SIGINT below is what a terminal's Ctrl-C sends.
    $runner = new Process(['setsid', PHP_BINARY, 'artisan', 'simulate:e2e', '--connection=sqlite'], base_path(), ['APP_ENV' => 'testing', 'XDEBUG_MODE' => 'off'], timeout: 180);
    $runner->start();
    $database = null;

    try {
        $deadline = microtime(true) + 90;

        // Wait until the scenario is under way (the database exists and the first step has printed).
        while ($runner->isRunning() && microtime(true) < $deadline && ! str_contains($runner->getOutput(), 'a cPanel server')) {
            usleep(100_000);
        }

        preg_match('/Scenario database (\S+billing_e2e_[0-9a-f]{8}\.sqlite)/', $runner->getOutput(), $match);
        $database = $match[1] ?? null;
        expect($database)->not->toBeNull()->and(is_file((string) $database))->toBeTrue();

        posix_kill(-$runner->getPid(), 2); // SIGINT (the pcntl constants may be missing)
        try {
            $runner->wait();
        } catch (ProcessSignaledException) {
            // At cf492f1 the runner dies of the signal instead of cleaning up and exiting.
        }

        // At cf492f1 the `finally` in E2eCommand::perform() never runs on SIGINT, so the database is left behind (on
        // PostgreSQL: a billing_e2e_* database on the shared server that `pg.sh --prune` does not drop either). Fix:
        // $this->trap([SIGINT, SIGTERM, SIGHUP], ...) to drop it, and let simulate:clean (or pg.sh --prune) drop
        // billing_e2e_* databases nobody is connected to.
        // Without pcntl (this codespace) the runner cannot trap the signal; its detached guard notices the runner is
        // gone and drops the database within a few hundred milliseconds. With pcntl the trap does it before exiting.
        $gone = microtime(true) + 5;

        while ((clearstatcache() || is_file((string) $database)) && microtime(true) < $gone) {
            usleep(100_000);
        }

        clearstatcache();

        expect(is_file((string) $database))->toBeFalse();
    } finally {
        if ($runner->isRunning()) {
            posix_kill(-$runner->getPid(), 9);
        }

        if (is_string($database) && str_starts_with(basename($database), 'billing_e2e_')) {
            @unlink($database);
        }
    }
});
