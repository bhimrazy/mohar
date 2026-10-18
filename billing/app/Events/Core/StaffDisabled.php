<?php

namespace App\Events\Core;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff account was deactivated; its sessions were revoked.
 */
final class StaffDisabled
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $userId,
    ) {}
}
