<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Review probe for PR #91: when the seed passed to useSeededDatabase() throws, the test's default connection must be
// restored. Otherwise RefreshDatabase rolls back the seeded connection instead of the test database, leaves the
// in-memory SQLite transaction open and marks the schema unmigrated, and every later test in that worker fails
// ("cannot VACUUM from within a transaction", "table migrations already exists").

it('restores the default connection when the seed fails', function () {
    $default = config('database.default');

    expect(fn () => $this->useSeededDatabase('probe_failing', fn () => throw new RuntimeException('seed failed')))
        ->toThrow(RuntimeException::class, 'seed failed')
        ->and(config('database.default'))->toBe($default);
});

it('runs the next test in the worker on a clean test database', function () {
    expect(DB::connection()->getName())->toBe(config('database.default'))
        ->and(Schema::hasTable('migrations'))->toBeTrue();
});
