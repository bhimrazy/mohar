<?php

namespace App\Modules\Billing\Support;

use Illuminate\Support\Facades\DB;

/**
 * Dispatch domain events only once the surrounding transaction commits (immediately when none is open),
 * so listeners in other modules never see uncommitted billing state.
 */
final class AfterCommit
{
    public static function dispatch(object ...$events): void
    {
        foreach ($events as $event) {
            DB::afterCommit(fn () => event($event));
        }
    }
}
