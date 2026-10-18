<?php

namespace App\Events\Support;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The inbound email pipeline dropped a message (status holds the reason).
 */
final class InboundEmailRejected
{
    use Dispatchable;

    public function __construct(
        public int $inboundEmailId,
        public string $reason,
    ) {}
}
