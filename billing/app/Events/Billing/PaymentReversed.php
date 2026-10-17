<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment was reversed (chargeback, or refund with reverse effects); consumers roll back what the payment granted.
 */
final class PaymentReversed
{
    use Dispatchable;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public int $transactionId,
        public int $amount,
        public string $currency,
        public string $reason,
    ) {}
}
