<?php

use Tests\Simulators\SimulatorHarness;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The end-to-end scenario's guards (ADR 0016 §5 guarantee 4): it runs only on a throwaway database, never the preview
 * or development one, and simulate:e2e makes that database itself.
 */
test('the harness refuses any database but a throwaway one', function (string $database) {
    config(['database.connections.sqlite.database' => $database, 'database.default' => 'sqlite']);
    $harness = new class
    {
        use SimulatorHarness;
    };

    expect(fn () => $harness->startSimulators())->toThrow(RuntimeException::class, 'throwaway database');
})->with([
    'the preview database' => ['database/preview.sqlite'],
    'the development database' => ['billing'],
    'a test database of another run' => ['billing_test_simulators'],
]);

test('simulate:e2e makes its database only on PostgreSQL or as a temporary SQLite file', function () {
    $this->artisan('simulate:e2e', ['--connection' => 'mysql']);
})->throws(RuntimeException::class, 'Use --connection=pgsql or --connection=sqlite.');
