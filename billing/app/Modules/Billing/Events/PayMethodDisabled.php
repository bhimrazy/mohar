<?php

namespace App\Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stored pay method was disabled after repeated capture failures.
 *
 * Module-local until promoted to App\Events\Billing (shared kernel change requested from the CTO).
 */
final class PayMethodDisabled
{
    use Dispatchable;

    public function __construct(
        public int $payMethodId,
        public int $clientId,
    ) {}
}
