<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

final class InvoiceCreated
{
    use Dispatchable;

    public function __construct(
        public int $invoiceId,
        public int $clientId,
        public bool $notify = true,
        public ?string $reason = null,
    ) {}
}
