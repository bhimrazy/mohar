<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

final class InvoiceOverdue
{
    use Dispatchable;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public int $daysOverdue,
    ) {}
}
