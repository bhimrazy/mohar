<?php

namespace App\Events\Core;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One account reached the failed sign-in threshold within an hour.
 */
final class StaffLoginFailuresThreshold
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $userId,
        public int $failures,
    ) {}
}
