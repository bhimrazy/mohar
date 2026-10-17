<?php

namespace App\Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment reduced the balance of an invoice without settling it.
 *
 * Module-local until promoted to App\Events\Billing (shared kernel change requested from the CTO).
 */
final class InvoicePartiallyPaid
{
    use Dispatchable;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public int $amountPaid,
        public int $balance,
        public string $currency,
    ) {}
}
