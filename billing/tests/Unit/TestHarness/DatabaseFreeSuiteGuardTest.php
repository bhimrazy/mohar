<?php

// Review probe for PR #91: tests/Unit and tests/Arch no longer run on PostgreSQL, so Tests\TestCase must refuse a
// query from them. This keeps the guard itself from silently going away.

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

it('refuses a query from a database-free suite', function () {
    expect(fn () => DB::select('select 1'))->toThrow(LogicException::class, 'database-free suite');
});
