<?php

namespace App\Modules\Core\Support;

use App\Contracts\Billing\InvoiceNumbers;

/**
 * Default when the owning module is not installed: no numbers are known.
 */
class NullInvoiceNumbers implements InvoiceNumbers
{
    public function numbers(array $invoiceIds): array
    {
        return [];
    }
}
