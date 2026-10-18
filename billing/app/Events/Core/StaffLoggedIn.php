<?php

namespace App\Events\Core;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff member signed in.
 */
final class StaffLoggedIn
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $userId,
    ) {}
}
