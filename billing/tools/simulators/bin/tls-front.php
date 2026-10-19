<?php

/*
 * The TLS front of a simulator scenario: TLS on the API port, piped to the `php -S` backend (ADR 0016 §1).
 */

use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Kernel\TlsFront;

require (string) getenv('SIM_AUTOLOAD');

$scenario = Scenario::load((string) getenv('SIM_SCENARIO_DIR'));
$owner = $scenario->owner();

// Watchdog: a scenario owned by a test process stops itself (its siblings and its directory) once that process is gone.
$alive = $owner === null ? null : function () use ($scenario, $owner): bool {
    if (posix_kill($owner, 0)) {
        return true;
    }

    Processes::stop($scenario, except: posix_getpid());

    return false;
};

(new TlsFront($scenario->ca()->leafPath(), $scenario->apiPort(), $scenario->backendPort(), $alive))->run();
