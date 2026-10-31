<?php

require_once dirname(__DIR__, 2).'/Billing/Support/race.php';

/**
 * runRaceWorkers() with the password-guess worker.
 *
 * @param  array<string, list<string>>  $workers
 * @return array<string, array<string, mixed>>
 */
function passwordGuessRaceWorkers(array $workers): array
{
    $prefix = (string) config('cache.prefix');
    $results = runRaceWorkers(array_map(fn (array $arguments): array => [...$arguments, $prefix], $workers), 30.0, __DIR__.'/password-guess-worker.php');

    // Otherwise the workers count where the test cannot see it, and the test means nothing.
    foreach ($results as $label => $result) {
        expect($result['cache_prefix'] ?? null)->toBe($prefix, "worker {$label} used another cache prefix");
    }

    return $results;
}
