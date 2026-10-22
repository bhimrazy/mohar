<?php

use PHPUnit\Framework\ExpectationFailedException;

/*
 * Review probes for PR #276 (issue #275).
 */

test('root cause: one target with the violation fails', function () {
    expect(fn () => expect('Tests\Fixtures\ArchProbe\Dirty')->not->toUse('Illuminate\Support\Facades\DB'))
        ->toThrow(ExpectationFailedException::class);
});

test('root cause: the same violation passes once a clean target is listed with it (pestphp/pest#1751)', function () {
    expect(fn () => expect(['Tests\Fixtures\ArchProbe\Clean', 'Tests\Fixtures\ArchProbe\Dirty'])->not->toUse('Illuminate\Support\Facades\DB'))
        ->not->toThrow(ExpectationFailedException::class)
        ->and(fn () => expect(['Tests\Fixtures\ArchProbe\Dirty', 'Tests\Fixtures\ArchProbe\Clean'])->not->toUse('Illuminate\Support\Facades\DB'))
        ->not->toThrow(ExpectationFailedException::class);
});

test('finding: a class-name target that no longer exists passes toBeFinal silently and is outside the guard', function () {
    expect(fn () => expect('App\Support\Services\NoSuchDriverRegistry')->toBeFinal())
        ->not->toThrow(ExpectationFailedException::class)
        // the guard reports an existing class FQCN as "no classes", so it cannot cover class targets as written
        ->and(archTargetsWithoutClasses(['App\Support\Services\ProvisioningDriverRegistry']))
        ->toBe(['App\Support\Services\ProvisioningDriverRegistry']);
});

afterAll(fn () => releaseArchCache());
