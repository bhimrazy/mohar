<?php

namespace Mohar\Simulators\Kernel;

use Mohar\Simulators\Contracts\Simulator;
use RuntimeException;

/**
 * Starts and stops a scenario's processes: the `php -S` API backend (4 workers), the `php -S` control plane and the TLS
 * front, each detached and recorded by pid, so `simulate:stop` and `simulate:clean` can reap them.
 *
 * A scenario a test starts is owned by the test process: the TLS front checks every second that the owner is alive and
 * otherwise stops the scenario, so a runner that is killed or times out leaves nothing running. `simulate:start`
 * starts detached scenarios. A pid is signalled only while /proc shows it is one of the scenario's own processes (it
 * carries the scenario directory in its environment), never a reused pid after a reboot. Needs Linux (`setsid`, /proc
 * and ext-posix).
 */
final class Processes
{
    private const READY_SECONDS = 20;

    /** A scenario directory without a readable scenario.json is a leftover only after this long (it may be starting). */
    private const LEFTOVER_SECONDS = 60;

    /**
     * @param  class-string<Simulator>  $simulator
     * @param  bool  $detached  true for `simulate:start`: the scenario outlives this process until stopped
     */
    public static function start(string $simulator, bool $detached = false): Scenario
    {
        $scenario = Scenario::create($simulator, $detached ? null : posix_getpid());
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
     *
     * @param  int|null  $except  a pid to leave running (the TLS front stopping its own scenario exits by itself)
     */
    public static function stop(Scenario $scenario, bool $keep = false, ?int $except = null): void
    {
        foreach ($scenario->pids() as $pid) {
            if ($pid !== $except && self::belongs($pid, $scenario)) {
                posix_kill($pid, 15); // SIGTERM (the pcntl constants may be missing)
            }
        }

        if (! $keep) {
            self::remove($scenario->directory);
        }
    }

    /**
     * Stop and remove this checkout's scenarios (`simulate:clean`): detached ones, those whose owner is gone and
     * leftovers. A scenario another live process owns (another test run in this checkout) is left alone; other
     * checkouts and parallel workers have roots of their own.
     *
     * @return list<string> the scenario ids removed
     */
    public static function clean(): array
    {
        $removed = [];

        foreach (glob(Scenario::root().'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            try {
                $scenario = Scenario::load($directory);
                $owner = $scenario->owner();

                if ($owner !== null && $owner !== posix_getpid() && posix_kill($owner, 0)) {
                    continue;
                }

                self::stop($scenario);
                $removed[] = $scenario->id();
            } catch (\InvalidArgumentException) {
                if (time() - (int) filemtime($directory) >= self::LEFTOVER_SECONDS) {
                    self::remove($directory);
                    $removed[] = basename($directory);
                }
            }
        }

        return $removed;
    }

    public static function running(Scenario $scenario): bool
    {
        return $scenario->pids() !== [] && array_filter($scenario->pids(), fn (int $pid): bool => ! self::belongs($pid, $scenario)) === [];
    }

    /**
     * Whether the pid is alive and one of this scenario's processes. Where /proc is missing, liveness is all there is.
     */
    public static function belongs(int $pid, Scenario $scenario): bool
    {
        if ($pid <= 0 || ! posix_kill($pid, 0)) {
            return false;
        }

        if (! is_dir('/proc/self')) {
            return true;
        }

        $environment = @file_get_contents("/proc/{$pid}/environ");

        return is_string($environment) && str_contains("\0{$environment}", "\0SIM_SCENARIO_DIR={$scenario->directory}\0");
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private static function spawn(array $command, array $environment, string $log): int
    {
        // Every descriptor above 2 is closed and every standard stream is given explicitly, so no process inherits the
        // caller's pipes or files: a test runner's output, or `$(php artisan simulate:start …)`, would otherwise stay
        // open, and wait, as long as the simulator runs.
        $line = 'for fd in /proc/$$/fd/*; do n=${fd##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            .'setsid '.implode(' ', array_map('escapeshellarg', $command)).' > '.escapeshellarg($log).' 2>&1 < /dev/null & echo $!';
        $process = proc_open(['/bin/bash', '-c', $line], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, [...getenv(), ...$environment]);

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
