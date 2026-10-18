<?php

namespace App\Events\Billing;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class QuoteAccepted
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $quoteId,
        public int $clientId,
        public ?int $invoiceId,
    ) {}
}
