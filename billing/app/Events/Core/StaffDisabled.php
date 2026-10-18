<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff account was deactivated; its sessions were revoked.
 */
final class StaffDisabled
{
    use Dispatchable;

    public function __construct(
        public int $userId,
    ) {}
}
