<?php

// Review probes for PR #91: TestCase::useSeededDatabase() must behave like the RefreshDatabase transaction it
// replaces. The app relies on after-commit callbacks (DB::afterCommit, ->afterCommit() jobs, ShouldDispatchAfterCommit
// events), so a test on the seeded database must see them run exactly as a normal Feature test does.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->useSeededDatabase('probe_harness', function () {
        DB::statement('CREATE TABLE probe_seeded (name VARCHAR(20) NOT NULL)');
        DB::table('probe_seeded')->insert(['name' => 'seed']);
    });
});

it('runs a test on the seeded data and rolls its writes back', function () {
    DB::table('probe_seeded')->insert(['name' => 'written']);

    expect(DB::table('probe_seeded')->count())->toBe(2);
});

it('starts each test from the seeded data only', function () {
    DB::table('probe_seeded')->insert(['name' => 'written']);

    expect(DB::table('probe_seeded')->count())->toBe(2);
});

it('runs an after-commit callback registered outside a nested transaction immediately, as RefreshDatabase does', function () {
    $ran = false;
    DB::afterCommit(function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});

it('runs an after-commit callback when the nested transaction that registered it commits', function () {
    $ran = false;
    DB::transaction(function () use (&$ran) {
        DB::afterCommit(function () use (&$ran) {
            $ran = true;
        });

        expect($ran)->toBeFalse();
    });

    expect($ran)->toBeTrue();
});
