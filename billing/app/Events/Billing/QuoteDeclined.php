<?php

namespace App\Events\Billing;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The client declined a quote.
 */
final class QuoteDeclined
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $quoteId,
        public int $clientId,
    ) {}
}
