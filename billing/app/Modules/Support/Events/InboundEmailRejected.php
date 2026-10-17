<?php

namespace App\Modules\Support\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The inbound email pipeline dropped a message (status holds the reason).
 *
 * Module-local until promoted to App\Events\Support by the shared-kernel owner (spec §12).
 */
final class InboundEmailRejected
{
    use Dispatchable;

    public function __construct(
        public int $inboundEmailId,
        public string $reason,
    ) {}
}
