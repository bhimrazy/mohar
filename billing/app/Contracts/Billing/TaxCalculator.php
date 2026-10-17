<?php

namespace App\Contracts\Billing;

use App\Data\Billing\InvoiceLineData;
use App\Data\Billing\TaxTotals;

/**
 * Implemented by the Billing module. Used for cart/quote previews.
 */
interface TaxCalculator
{
    /**
     * @param  list<InvoiceLineData>  $lines
     */
    public function calculate(array $lines, string $currency, ?string $country, ?string $state, bool $taxExempt = false): TaxTotals;

    /**
     * @param  list<InvoiceLineData>  $lines
     */
    public function calculateForClient(array $lines, int $clientId, string $currency): TaxTotals;
}
