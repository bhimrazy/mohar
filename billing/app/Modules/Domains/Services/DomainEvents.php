<?php

namespace App\Modules\Domains\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Dispatches domain events after the surrounding transaction commits (immediately when none is open),
 * so listeners never observe rolled-back state.
 */
final class DomainEvents
{
    public static function dispatch(object $event): void
    {
        DB::afterCommit(fn () => Event::dispatch($event));
    }
}
