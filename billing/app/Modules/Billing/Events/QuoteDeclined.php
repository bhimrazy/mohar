<?php

namespace App\Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The client declined a quote.
 *
 * Module-local until promoted to App\Events\Billing (shared kernel change requested from the CTO).
 */
final class QuoteDeclined
{
    use Dispatchable;

    public function __construct(
        public int $quoteId,
        public int $clientId,
    ) {}
}
