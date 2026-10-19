<?php

/*
 * The TLS front of a simulator scenario: TLS on the API port, piped to the `php -S` backend (ADR 0016 §1).
 */

use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Kernel\TlsFront;

require (string) getenv('SIM_AUTOLOAD');

$scenario = Scenario::load((string) getenv('SIM_SCENARIO_DIR'));
(new TlsFront($scenario->ca()->leafPath(), $scenario->apiPort(), $scenario->backendPort()))->run();
