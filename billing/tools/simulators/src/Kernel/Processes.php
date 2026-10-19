<?php

namespace Mohar\Simulators\Kernel;

use Mohar\Simulators\Contracts\Simulator;
use RuntimeException;

/**
 * Starts and stops a scenario's processes: the `php -S` API backend (4 workers), the `php -S` control plane and the TLS
 * front, each detached and recorded by pid, so `simulate:stop` and `simulate:clean` can reap them.
 */
final class Processes
{
    private const READY_SECONDS = 10;

    /**
     * @param  class-string<Simulator>  $simulator
     */
    public static function start(string $simulator): Scenario
    {
        $scenario = Scenario::create($simulator);
        $bin = dirname(__DIR__, 2).'/bin';
        $environment = [
            'SIM_AUTOLOAD' => self::autoloader(),
            'SIM_SCENARIO_DIR' => $scenario->directory,
        ];

        try {
            $scenario->withPids([
                self::spawn([PHP_BINARY, '-S', '127.0.0.1:'.$scenario->backendPort(), $bin.'/router.php'], [...$environment, 'SIM_ROLE' => 'api', 'PHP_CLI_SERVER_WORKERS' => '4'], $scenario->directory.'/api.log'),
                self::spawn([PHP_BINARY, '-S', '127.0.0.1:'.$scenario->controlPort(), $bin.'/router.php'], [...$environment, 'SIM_ROLE' => 'control'], $scenario->directory.'/control.log'),
                self::spawn([PHP_BINARY, $bin.'/tls-front.php'], $environment, $scenario->directory.'/front.log'),
            ]);
            self::waitUntilReady($scenario);
        } catch (RuntimeException $exception) {
            self::stop($scenario);

            throw $exception;
        }

        return $scenario;
    }

    /**
     * Stop the scenario's processes and, unless kept, remove its directory.
     */
    public static function stop(Scenario $scenario, bool $keep = false): void
    {
        foreach ($scenario->pids() as $pid) {
            if ($pid > 0 && posix_kill($pid, 0)) {
                posix_kill($pid, 15); // SIGTERM (the pcntl constants may be missing)
            }
        }

        if (! $keep) {
            self::remove($scenario->directory);
        }
    }

    /**
     * Stop and remove every scenario under the temp root (`simulate:clean`).
     *
     * @return list<string> the scenario ids removed
     */
    public static function clean(): array
    {
        $removed = [];

        foreach (glob(Scenario::root().'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            try {
                $scenario = Scenario::load($directory);
                self::stop($scenario);
                $removed[] = $scenario->id();
            } catch (\InvalidArgumentException) {
                self::remove($directory);
                $removed[] = basename($directory);
            }
        }

        return $removed;
    }

    public static function running(Scenario $scenario): bool
    {
        return $scenario->pids() !== [] && array_filter($scenario->pids(), fn (int $pid): bool => ! posix_kill($pid, 0)) === [];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private static function spawn(array $command, array $environment, string $log): int
    {
        $line = 'setsid '.implode(' ', array_map('escapeshellarg', $command)).' > '.escapeshellarg($log).' 2>&1 < /dev/null & echo $!';
        // Every standard stream is given explicitly, so no process inherits the caller's pipes (a test runner's output
        // would otherwise stay open as long as the simulator runs).
        $process = proc_open(['/bin/sh', '-c', $line], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, [...getenv(), ...$environment]);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start a simulator process.');
        }

        $pid = (int) trim((string) stream_get_contents($pipes[1]));
        proc_close($process);

        return $pid > 0 ? $pid : throw new RuntimeException('A simulator process did not report its pid.');
    }

    private static function waitUntilReady(Scenario $scenario): void
    {
        $deadline = microtime(true) + self::READY_SECONDS;
        $context = stream_context_create(['ssl' => ['cafile' => $scenario->ca()->bundlePath(), 'peer_name' => 'localhost'], 'http' => ['header' => 'X-Sim-Token: '.$scenario->token(), 'ignore_errors' => true, 'timeout' => 2]]);

        while (microtime(true) < $deadline) {
            $front = @stream_socket_client('ssl://127.0.0.1:'.$scenario->apiPort(), $errorCode, $errorMessage, 1, STREAM_CLIENT_CONNECT, $context);
            $control = @file_get_contents($scenario->controlUrl().'/__sim/health', false, $context);

            if ($front !== false && is_string($control) && str_contains($control, '"ok":true')) {
                fclose($front);

                return;
            }

            usleep(100_000);
        }

        throw new RuntimeException("Simulator {$scenario->id()} did not become ready; see the logs in {$scenario->directory}.");
    }

    private static function autoloader(): string
    {
        foreach ([dirname(__DIR__, 4).'/vendor/autoload.php', dirname(__DIR__, 5).'/autoload.php'] as $candidate) {
            if (is_file($candidate)) {
                return (string) realpath($candidate);
            }
        }

        throw new RuntimeException('The application autoloader was not found.');
    }

    private static function remove(string $directory): void
    {
        if (! is_dir($directory) || ! str_starts_with(realpath($directory) ?: $directory, Scenario::root().'/')) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }
}
