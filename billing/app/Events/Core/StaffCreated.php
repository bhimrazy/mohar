<?php

namespace App\Events\Core;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff account was created (admin-set password or accepted invitation).
 */
final class StaffCreated
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $userId,
    ) {}
}
