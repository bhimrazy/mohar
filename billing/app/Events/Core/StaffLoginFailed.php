<?php

namespace App\Events\Core;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A staff sign-in attempt failed (userId is null when the email is unknown).
 */
final class StaffLoginFailed implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public ?int $userId,
    ) {}
}
