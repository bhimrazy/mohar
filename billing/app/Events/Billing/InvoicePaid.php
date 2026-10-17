<?php

namespace App\Events\Billing;

use App\Data\Billing\InvoiceLineData;
use Illuminate\Foundation\Events\Dispatchable;

final class InvoicePaid
{
    use Dispatchable;

    /**
     * @param  list<InvoiceLineData>  $lines
     */
    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public array $lines,
    ) {}
}
