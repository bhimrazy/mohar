<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

final class QuoteAccepted
{
    use Dispatchable;

    public function __construct(
        public int $quoteId,
        public int $clientId,
        public ?int $invoiceId,
    ) {}
}
