<?php

/**
 * runRaceWorkers() (tests/Feature/Billing/Support/race.php) with this module's worker script.
 *
 * @param  array<string, list<string>>  $workers
 * @return array<string, array<string, mixed>>
 */
function kycRaceWorkers(array $workers, float $timeoutSeconds = 30.0, string $file = 'kyc-race-worker.php'): array
{
    $script = __DIR__.'/'.$file;
    $running = [];

    try {
        foreach ($workers as $label => $arguments) {
            $process = proc_open([PHP_BINARY, $script, $label, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            expect($process)->not->toBeFalse("worker $label did not start");
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $running[$label] = ['process' => $process, 'pipes' => $pipes, 'out' => '', 'err' => ''];
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $open = false;

            foreach ($running as &$worker) {
                $worker['out'] .= (string) stream_get_contents($worker['pipes'][1]);
                $worker['err'] .= (string) stream_get_contents($worker['pipes'][2]);
                $open = $open || ! feof($worker['pipes'][1]) || ! feof($worker['pipes'][2]);
            }
            unset($worker);

            if (! $open) {
                break;
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException('kyc race workers still running after '.$timeoutSeconds.' s');
            }

            usleep(20_000);
        }
    } finally {
        foreach ($running as $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process'], 9);
            }

            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
        }
    }

    $results = [];

    foreach ($running as $label => $worker) {
        $result = json_decode(trim($worker['out']), true);
        expect($result)->toBeArray("worker $label printed no result: ".$worker['out'].$worker['err'])
            ->and(array_key_exists('error', $result))->toBeFalse("worker $label failed: ".($result['error'] ?? '').' '.$worker['err']);
        $results[$label] = $result;
    }

    return $results;
}
