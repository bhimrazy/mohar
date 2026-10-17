<?php

namespace App\Modules\Core\Support;

use App\Contracts\Billing\InvoiceQueries;

/**
 * Default when the owning module is not installed.
 */
class NullInvoiceQueries implements InvoiceQueries
{
    public function hasPayments(int $invoiceId): bool
    {
        return false;
    }
}
