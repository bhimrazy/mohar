<?php

namespace App\Events\Billing;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A late fee debit adjustment was added to an overdue invoice.
 */
final class LateFeeApplied
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public int $amount,
        public string $currency,
    ) {}
}
