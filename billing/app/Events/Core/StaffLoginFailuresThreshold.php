<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One account reached the failed sign-in threshold within an hour.
 */
final class StaffLoginFailuresThreshold
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public int $failures,
    ) {}
}
