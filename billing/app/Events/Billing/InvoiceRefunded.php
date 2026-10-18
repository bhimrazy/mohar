<?php

namespace App\Events\Billing;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

final class InvoiceRefunded
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public int $amount,
        public string $currency,
        public bool $fullRefund,
        public bool $reverseCommission = true,
    ) {}
}
