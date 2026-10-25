<?php

use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\SimulatorRegistry;
use Mohar\Simulators\Tests\Fixtures\EchoSimulator;
use Tests\TestCase;

uses(TestCase::class);

// Only what this test started: other processes and workers may have scenarios running in the same root.
beforeEach(fn () => $this->before = glob(Scenario::root().'/*', GLOB_ONLYDIR) ?: []);

afterEach(function () {
    foreach (array_diff(glob(Scenario::root().'/*', GLOB_ONLYDIR) ?: [], $this->before) as $directory) {
        Processes::stop(Scenario::load($directory));
    }
});

test('a simulator starts on free ports, prints what the app needs, and simulate:clean reaps it', function () {
    app(SimulatorRegistry::class)->register(EchoSimulator::class);

    $this->artisan('simulate:list')->expectsOutputToContain('https://example.test/echo-api')->assertSuccessful();
    $this->artisan('simulate:start', ['provider' => 'echo'])
        ->expectsOutputToContain('INTEGRATION_CA_BUNDLE=')
        ->expectsOutputToContain('INTEGRATION_ENDPOINTS={"echo.api":"https:\/\/127.0.0.1:')
        ->assertSuccessful();

    $directory = array_values(array_diff(glob(Scenario::root().'/echo-*', GLOB_ONLYDIR) ?: [], $this->before))[0];
    expect(Processes::running(Scenario::load($directory)))->toBeTrue();

    $this->artisan('simulate:clean')->assertSuccessful();
    expect(is_dir($directory))->toBeFalse();
});

test('an unknown simulator is named with the installed ones', function () {
    $this->artisan('simulate:start', ['provider' => 'nosuchprovider'])->assertFailed();
})->throws(InvalidArgumentException::class, 'installed:');

test('every simulate command refuses to run outside local and testing', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    $this->artisan('simulate:list')->expectsOutputToContain('Simulators run only when APP_ENV is local or testing.')->assertFailed();
    $this->artisan('simulate:clean')->assertFailed();
})->with(['production', 'staging']);
