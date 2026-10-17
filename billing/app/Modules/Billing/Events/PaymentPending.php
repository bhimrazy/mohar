<?php

namespace App\Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A gateway accepted a payment that settles later (invoice is payment_pending).
 *
 * Module-local until promoted to App\Events\Billing (shared kernel change requested from the CTO).
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
