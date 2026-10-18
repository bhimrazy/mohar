<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff account was created (admin-set password or accepted invitation).
 */
final class StaffCreated
{
    use Dispatchable;

    public function __construct(
        public int $userId,
    ) {}
}
