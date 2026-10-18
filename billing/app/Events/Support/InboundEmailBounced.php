<?php

namespace App\Events\Support;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An inbound email must be answered with a bounce template (ticket.closed_bounce, ...).
 */
final class InboundEmailBounced
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $inboundEmailId,
        public string $template,
        public string $recipient,
    ) {}
}
