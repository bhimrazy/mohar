<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

final class InvoiceCancelled
{
    use Dispatchable;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
    ) {}
}
