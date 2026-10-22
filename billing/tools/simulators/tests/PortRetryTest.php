<?php

/*
 * Issue #182: Scenario ports are chosen free, then bound by separate processes, so another process on the machine can
 * take one in between. Processes::start() retries such a start on fresh ports; any other start failure is not retried.
 */

use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Tests\Fixtures\EchoSimulator;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // A port another process holds: listening, so a bind to it fails with "Address already in use".
    $this->held = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($this->held, false);
    $this->heldPort = (int) substr($name, strrpos($name, ':') + 1);
    $this->before = glob(Scenario::root().'/echo-*', GLOB_ONLYDIR) ?: [];
    $this->attempts = [];
});

afterEach(function () {
    fclose($this->held);

    foreach (array_diff(glob(Scenario::root().'/echo-*', GLOB_ONLYDIR) ?: [], $this->before) as $directory) {
        Processes::stop(Scenario::load($directory));
    }
});

/**
 * Ports for one attempt: free ones, with the held port in the given role.
 *
 * @return array{api: int, backend: int, control: int}
 */
function portsWithHeld(string $role, int $held): array
{
    return [...array_combine(['api', 'backend', 'control'], Scenario::freePorts(3)), $role => $held];
}

test('a port taken before the simulator binds it is retried on fresh ports', function (string $role) {
    $scenario = Processes::start(EchoSimulator::class, ports: function (int $attempt) use ($role) {
        $this->attempts[] = $attempt;

        return $attempt === 1 ? portsWithHeld($role, $this->heldPort) : null;
    });

    expect($this->attempts)->toBe([1, 2])
        ->and(Processes::running($scenario))->toBeTrue()
        ->and([$scenario->apiPort(), $scenario->backendPort(), $scenario->controlPort()])->not->toContain($this->heldPort)
        // The failed attempt is stopped and removed: only the running scenario is left.
        ->and(array_values(array_diff(glob(Scenario::root().'/echo-*', GLOB_ONLYDIR) ?: [], $this->before)))->toBe([$scenario->directory]);
})->with([
    'TLS front (api)' => 'api',
    'php -S backend' => 'backend',
    'php -S control plane' => 'control',
]);

test('a port taken on every attempt fails after three, with the logs kept', function () {
    $message = '';

    try {
        Processes::start(EchoSimulator::class, ports: function (int $attempt) {
            $this->attempts[] = $attempt;

            return portsWithHeld('control', $this->heldPort);
        });
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }

    preg_match('#see the logs in (\S+?)\.?$#', $message, $match);

    expect($this->attempts)->toBe([1, 2, 3])
        ->and($message)->toStartWith('A port was taken on all 3 attempts.')->toContain('Address already in use')
        ->and(is_file(($match[1] ?? '/nonexistent').'/control.log'))->toBeTrue()
        // The two retried attempts are removed; only the last one's directory stays, for its logs.
        ->and(count(array_diff(glob(Scenario::root().'/echo-*', GLOB_ONLYDIR) ?: [], $this->before)))->toBe(1);
});

test('scenario ports are distinct and never the forbidden ones', function () {
    $ports = Scenario::freePorts(50);

    expect(array_unique($ports))->toHaveCount(50)
        ->and(array_intersect($ports, Scenario::FORBIDDEN_PORTS))->toBe([]);
});
