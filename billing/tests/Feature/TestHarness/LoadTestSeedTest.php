<?php

use LoadTest\Seed\LoadTestSeeder;
use LoadTest\Seed\Verifier;
use LoadTest\Seed\Volume;

/*
 * The load-test seed (loadtest/seed) bulk-inserts rows and calls app classes such as DocumentNumbers directly, so an
 * app change can break it with nothing failing until a 25-minute runner run (the CalendarDate change did). A small
 * seed here keeps it in step with the app.
 */

beforeEach(function () {
    foreach (['Calendar', 'BulkWriter', 'Volume', 'LoadTestSeeder', 'Verifier'] as $class) {
        require_once __DIR__."/../../../../loadtest/seed/{$class}.php";
    }
});

test('the load-test seed runs against the current app and every invariant holds', function () {
    $seeder = new LoadTestSeeder(new Volume('test', clients: 20, services: 40, staff: 2), 'load-test-password', 2026, 25, fn (string $line) => null);

    $summary = $seeder->run();

    expect($summary['clients'])->toBe(20)
        ->and($summary['invoices'])->toBeGreaterThan(0)
        ->and(array_filter((new Verifier)->run()))->toBe([]);
});
