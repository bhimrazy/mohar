<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A role was created or its permissions changed.
 */
final class RoleUpdated
{
    use Dispatchable;

    public function __construct(
        public int $roleId,
    ) {}
}
