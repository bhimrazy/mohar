<?php

namespace App\Events\Billing;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stored pay method was disabled after repeated capture failures.
 */
final class PayMethodDisabled implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $payMethodId,
        public int $clientId,
    ) {}
}
