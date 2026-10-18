<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A gateway accepted a payment that settles later (invoice is payment_pending).
 */
final class PaymentPending
{
    use Dispatchable;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public string $gateway,
    ) {}
}
