<?php

use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\SimulatorRegistry;
use Mohar\Simulators\Tests\Fixtures\EchoSimulator;
use Tests\TestCase;

uses(TestCase::class);

afterEach(fn () => Processes::clean());

test('a simulator starts on free ports, prints what the app needs, and simulate:clean reaps it', function () {
    app(SimulatorRegistry::class)->register(EchoSimulator::class);

    $this->artisan('simulate:list')->expectsOutputToContain('https://example.test/echo-api')->assertSuccessful();
    $this->artisan('simulate:start', ['provider' => 'echo'])
        ->expectsOutputToContain('INTEGRATION_CA_BUNDLE=')
        ->expectsOutputToContain('INTEGRATION_ENDPOINTS={"echo.api":"https:\/\/127.0.0.1:')
        ->assertSuccessful();

    $directory = glob(Scenario::root().'/echo-*')[0];
    expect(Processes::running(Scenario::load($directory)))->toBeTrue();

    $this->artisan('simulate:clean')->assertSuccessful();
    expect(is_dir($directory))->toBeFalse();
});

test('an unknown simulator is named with the installed ones', function () {
    $this->artisan('simulate:start', ['provider' => 'stripe'])->assertFailed();
})->throws(InvalidArgumentException::class, 'installed:');

test('every simulate command refuses to run outside local and testing', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    $this->artisan('simulate:list')->expectsOutputToContain('Simulators run only when APP_ENV is local or testing.')->assertFailed();
    $this->artisan('simulate:clean')->assertFailed();
})->with(['production', 'staging']);
