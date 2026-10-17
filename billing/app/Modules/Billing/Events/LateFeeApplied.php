<?php

namespace App\Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A late fee debit adjustment was added to an overdue invoice.
 *
 * Module-local until promoted to App\Events\Billing (shared kernel change requested from the CTO).
 */
final class LateFeeApplied
{
    use Dispatchable;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public int $amount,
        public string $currency,
    ) {}
}
