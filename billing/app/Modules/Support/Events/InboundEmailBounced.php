<?php

namespace App\Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An inbound email must be answered with a bounce template (ticket.closed_bounce, ...).
 *
 * Module-local until promoted to App\Events\Support by the shared-kernel owner (spec §12).
 */
final class InboundEmailBounced
{
    use Dispatchable;

    public function __construct(
        public int $inboundEmailId,
        public string $template,
        public string $recipient,
    ) {}
}
