<?php

namespace App\Events\Core;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One account reached the failed sign-in threshold within an hour.
 */
final class StaffLoginFailuresThreshold implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $userId,
        public int $failures,
    ) {}
}
