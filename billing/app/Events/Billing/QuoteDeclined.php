<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The client declined a quote.
 */
final class QuoteDeclined
{
    use Dispatchable;

    public function __construct(
        public int $quoteId,
        public int $clientId,
    ) {}
}
