<?php

namespace App\Events\Support;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The inbound email pipeline dropped a message (status holds the reason).
 */
final class InboundEmailRejected implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $inboundEmailId,
        public string $reason,
    ) {}
}
