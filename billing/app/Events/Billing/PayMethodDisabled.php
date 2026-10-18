<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stored pay method was disabled after repeated capture failures.
 */
final class PayMethodDisabled
{
    use Dispatchable;

    public function __construct(
        public int $payMethodId,
        public int $clientId,
    ) {}
}
