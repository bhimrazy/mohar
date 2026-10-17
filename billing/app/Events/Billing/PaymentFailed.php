<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

final class PaymentFailed
{
    use Dispatchable;

    public function __construct(
        public ?int $invoiceId,
        public int $clientId,
        public string $gateway,
        public string $reason,
    ) {}
}
