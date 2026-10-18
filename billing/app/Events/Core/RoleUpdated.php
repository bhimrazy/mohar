<?php

namespace App\Events\Core;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A role was created or its permissions changed.
 */
final class RoleUpdated
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $roleId,
    ) {}
}
