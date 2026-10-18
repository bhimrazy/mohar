<?php

namespace App\Events\Billing;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment reduced the balance of an invoice without settling it.
 */
final class InvoicePartiallyPaid
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public int $amountPaid,
        public int $balance,
        public string $currency,
    ) {}
}
