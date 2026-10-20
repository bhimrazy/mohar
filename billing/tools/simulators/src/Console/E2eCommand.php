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
    {--keep : Keep the throwaway database for inspection}
    {--budget=360 : Seconds the scenario may take; over budget fails}')]
#[Description('Run the end-to-end hosting scenario (ADR 0016 §4) against the whm and stripe simulators on a throwaway database')]
final class E2eCommand extends SimulatorCommand
{
    private const SCENARIO = 'tests/Simulators';

    protected function perform(): int
    {
        $connection = (string) $this->option('connection');
        $name = 'billing_e2e_'.bin2hex(random_bytes(4));
        $environment = $this->createDatabase($connection, $name);
        $budget = max(60, (int) $this->option('budget'));
        $started = microtime(true);

        $this->components->info("Scenario database {$environment['DB_DATABASE']} on {$connection}.");

        try {
            $process = new Process(
                [PHP_BINARY, 'vendor/bin/pest', self::SCENARIO, '--colors=never'],
                base_path(),
                [...$this->testEnvironment(), 'XDEBUG_MODE' => 'off', 'DB_CONNECTION' => $connection, 'DB_URL' => '', ...$environment],
                timeout: $budget,
            );
            $process->run(fn (string $type, string $output) => $this->output->write($output));
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
                $this->components->warn("Kept {$environment['DB_DATABASE']}; drop it when done.");
            } else {
                $this->dropDatabase($connection, $environment['DB_DATABASE']);
            }
        }
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
            if (str_starts_with(basename($database), 'billing_e2e_')) {
                @unlink($database);
            }

            return;
        }

        if (preg_match('/^billing_e2e_[0-9a-f]{8}$/', $database) === 1) {
            DB::connection('pgsql')->statement("DROP DATABASE IF EXISTS \"{$database}\" WITH (FORCE)");
        }
    }
}
