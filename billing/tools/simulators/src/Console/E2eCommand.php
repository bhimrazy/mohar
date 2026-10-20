<?php

namespace Mohar\Simulators\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[Signature('simulate:e2e
    {--connection=pgsql : The database server to create the throwaway database on (pgsql, or sqlite for a temporary file)}
    {--filter= : Run only the scenario tests whose name matches (with --keep, to keep that test\'s state)}
    {--keep : Keep the throwaway database for inspection; it holds the state of the last test run (use --filter to choose it)}
    {--budget=360 : Seconds the scenario may take, at least 60; over budget fails}')]
#[Description('Run the end-to-end hosting scenario (ADR 0016 §4) against the whm and stripe simulators on a throwaway database')]
final class E2eCommand extends SimulatorCommand
{
    private const SCENARIO = 'tests/Simulators';

    private const MIN_BUDGET = 60;

    protected function perform(): int
    {
        $budget = (int) $this->option('budget');

        if ($budget < self::MIN_BUDGET) {
            $this->components->error('The budget must be at least '.self::MIN_BUDGET.' seconds: install, migrations and two scenarios need that much.');

            return self::FAILURE;
        }

        $connection = (string) $this->option('connection');
        $environment = $this->createDatabase($connection, 'billing_e2e_'.bin2hex(random_bytes(4)));
        $database = $environment['DB_DATABASE'];
        $state = sys_get_temp_dir().'/'.basename($database).'.guard.json';
        file_put_contents($state, json_encode(['child' => null]));
        $this->startGuard($state, $connection, $database);
        $this->trapInterrupts($state, $connection, $database);
        $started = microtime(true);

        $this->components->info("Scenario database {$database} on {$connection}.");

        try {
            $filter = (string) $this->option('filter');
            $process = new Process(
                [PHP_BINARY, 'vendor/bin/pest', self::SCENARIO, '--colors=never', ...($filter !== '' ? ['--filter='.$filter] : [])],
                base_path(),
                [...$this->testEnvironment(), 'XDEBUG_MODE' => 'off', 'DB_CONNECTION' => $connection, 'DB_URL' => '', ...$environment],
                timeout: $budget,
            );
            $process->start(fn (string $type, string $output) => $this->output->write($output));
            file_put_contents($state, json_encode(['child' => $process->getPid()]));
            $process->wait();
            $seconds = (int) round(microtime(true) - $started);

            if (! $process->isSuccessful()) {
                $this->components->error("The scenario failed after {$seconds} s.");

                return self::FAILURE;
            }

            $this->components->info("The scenario passed in {$seconds} s (budget {$budget} s).");

            return self::SUCCESS;
        } catch (ProcessTimedOutException) {
            $this->components->error("The scenario ran over its budget of {$budget} s.");

            return self::FAILURE;
        } finally {
            if ($this->option('keep')) {
                @unlink($state);
                $this->components->warn("Kept {$database}; drop it when done (pg.sh --prune drops idle ones).");
            } else {
                $this->dropDatabase($connection, $database);
                @unlink($state);
            }
        }
    }

    /**
     * The detached guard (bin/e2e-guard.php): if this command dies without cleaning up, it stops the scenario run and
     * drops the database. Removing the state file ends it.
     */
    private function startGuard(string $state, string $connection, string $database): void
    {
        if ($this->option('keep')) {
            return;
        }

        $pgsql = (array) config('database.connections.pgsql');
        $environment = [
            'E2E_DB_CONNECTION' => $connection,
            'E2E_DB_NAME' => $database,
            'E2E_PG_DSN' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $pgsql['host'] ?? '127.0.0.1', $pgsql['port'] ?? 5432, $pgsql['database'] ?? 'postgres'),
            'E2E_PG_USER' => (string) ($pgsql['username'] ?? ''),
            'E2E_PG_PASSWORD' => (string) ($pgsql['password'] ?? ''),
        ];
        $line = 'for fd in /proc/$$/fd/*; do n=${fd##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; setsid '
            .implode(' ', array_map('escapeshellarg', [PHP_BINARY, dirname(__DIR__, 2).'/bin/e2e-guard.php', (string) getmypid(), $state]))
            .' > /dev/null 2>&1 < /dev/null &';
        $guard = proc_open(['/bin/bash', '-c', $line], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, [...getenv(), ...$environment]);

        if (is_resource($guard)) {
            proc_close($guard);
        }
    }

    /**
     * Where pcntl is loaded, Ctrl-C and SIGTERM clean up at once, then re-raise the signal with its default action, so the
     * process ends the way the caller expects (128 + signal); elsewhere the guard does it.
     */
    private function trapInterrupts(string $state, string $connection, string $database): void
    {
        if (! extension_loaded('pcntl') || $this->option('keep')) {
            return;
        }

        $this->trap([2, 15, 1], function (int $signal) use ($state, $connection, $database): void {
            $info = json_decode((string) @file_get_contents($state), true);

            if (is_array($info) && (int) ($info['child'] ?? 0) > 0) {
                posix_kill((int) $info['child'], 15);
            }

            $this->dropDatabase($connection, $database);
            @unlink($state);

            pcntl_signal($signal, SIG_DFL);
            posix_kill(posix_getpid(), $signal);
        });
    }

    /**
     * phpunit.xml's environment, passed explicitly: this command's own environment (and its .env, such as a database
     * queue) would otherwise reach the scenario, because phpunit's values do not override variables already set.
     *
     * @return array<string, string>
     */
    private function testEnvironment(): array
    {
        $environment = [];
        $phpunit = simplexml_load_file(base_path('phpunit.xml'));

        foreach ($phpunit !== false ? $phpunit->xpath('/phpunit/php/env') ?: [] : [] as $variable) {
            $environment[(string) $variable['name']] = (string) $variable['value'];
        }

        return $environment;
    }

    /**
     * A new database only this run uses, named billing_e2e_<random>: never the development or preview database.
     *
     * @return array{DB_DATABASE: string}
     */
    private function createDatabase(string $connection, string $name): array
    {
        if ($connection === 'sqlite') {
            $file = sys_get_temp_dir()."/{$name}.sqlite";
            touch($file);

            return ['DB_DATABASE' => $file];
        }

        if ($connection !== 'pgsql') {
            throw new RuntimeException('Use --connection=pgsql or --connection=sqlite.');
        }

        DB::connection('pgsql')->statement("CREATE DATABASE \"{$name}\"");

        return ['DB_DATABASE' => $name];
    }

    private function dropDatabase(string $connection, string $database): void
    {
        if ($connection === 'sqlite') {
            if (preg_match('/^billing_e2e_[0-9a-f]{8}\.sqlite$/', basename($database)) === 1) {
                @unlink($database);
            }

            return;
        }

        if (preg_match('/^billing_e2e_[0-9a-f]{8}$/', $database) === 1) {
            DB::connection('pgsql')->statement("DROP DATABASE IF EXISTS \"{$database}\" WITH (FORCE)");
        }
    }
}
