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
    return runRaceWorkers($workers, 30.0, __DIR__.'/password-guess-worker.php');
}
