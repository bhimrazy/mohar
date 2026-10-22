<?php

namespace Tests\Fixtures\ArchProbe\Dirty;

use Illuminate\Support\Facades\DB;

/** Review probe for PR #276: a class that breaks a "no DB facade" rule. */
final class UsesDb
{
    public function run(): void
    {
        DB::select('select 1');
    }
}
