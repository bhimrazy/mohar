<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff member signed in.
 */
final class StaffLoggedIn
{
    use Dispatchable;

    public function __construct(
        public int $userId,
    ) {}
}
