<?php

namespace App\Modules\Core\Support;

use App\Contracts\Orders\PaymentGuard;

/**
 * Default when the owning module is not installed.
 */
class NullPaymentGuard implements PaymentGuard
{
    public function refusalReason(int $invoiceId): ?string
    {
        return null;
    }
}
