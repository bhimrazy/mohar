<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

final class InvoiceRefunded
{
    use Dispatchable;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public int $amount,
        public string $currency,
        public bool $fullRefund,
    ) {}
}
