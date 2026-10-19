<?php

/*
 * Router for the `php -S` processes of a simulator scenario (ADR 0016 §1): SIM_ROLE=api answers the provider's API
 * behind the TLS front; SIM_ROLE=control answers /__sim/* on the control port. Runs without booting the app.
 */

use Mohar\Simulators\Http\ApiKernel;
use Mohar\Simulators\Http\ControlPlane;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;

require (string) getenv('SIM_AUTOLOAD');

$scenario = Scenario::load((string) getenv('SIM_SCENARIO_DIR'));
$request = Request::fromGlobals();
$response = getenv('SIM_ROLE') === 'control'
    ? (str_starts_with($request->path, '/__sim/') ? (new ControlPlane($scenario))->handle($request) : new Response(404))
    : (new ApiKernel($scenario))->handle($request);

http_response_code($response->status);

// An explicit length, so a body the TLS front cuts short (truncate fault) is seen as truncated by the client.
foreach ([...$response->headers, 'Content-Length' => (string) strlen($response->body)] as $name => $value) {
    header("{$name}: {$value}");
}

echo $response->body;
