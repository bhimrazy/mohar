<?php

namespace App\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

final class PaymentReceived
{
    use Dispatchable;

    public function __construct(
        public int $transactionId,
        public ?int $invoiceId,
        public int $clientId,
        public int $amount,
        public string $currency,
        public string $gateway,
    ) {}
}
