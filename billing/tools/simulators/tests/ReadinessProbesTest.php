<?php

/*
 * PR #113 re-check probe (head 4a677e8b): READY_SECONDS went from 10 to 20. A simulator whose processes die at start
 * must fail fast and keep the logs its error message points to, not wait out the full window and then delete them.
 */

use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Scenario;
use Tests\TestCase;

uses(TestCase::class);

/** Defined only in this file, so the spawned processes cannot load it and exit at once. */
final class Rr113UnloadableSimulator implements Simulator
{
    public function key(): string
    {
        return 'unloadable';
    }

    public function reference(): array
    {
        return ['url' => 'https://example.test/unloadable', 'version' => '1'];
    }

    public function routes(): array
    {
        return [];
    }

    public function seed(Scenario $scenario): void {}

    public function appConfiguration(Scenario $scenario): array
    {
        return ['env' => [], 'notes' => []];
    }

    public function operations(): array
    {
        return [];
    }
}

test('a simulator that dies at start fails fast and keeps the logs its error names', function () {
    $started = microtime(true);
    $message = '';

    try {
        Processes::start(Rr113UnloadableSimulator::class);
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }

    preg_match('#see the logs in (\S+?)\.?$#', $message, $match);

    $elapsed = microtime(true) - $started;

    expect($message)->toContain('did not become ready')
        ->and(['logs kept' => isset($match[1]) && is_dir($match[1]), 'fast' => $elapsed < 5.0])->toBe(['logs kept' => true, 'fast' => true]);
});
