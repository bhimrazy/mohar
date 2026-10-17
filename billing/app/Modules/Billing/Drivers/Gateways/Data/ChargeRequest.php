<?php

namespace App\Modules\Billing\Drivers\Gateways\Data;

use App\Support\Money;

final readonly class ChargeRequest
{
    public function __construct(
        public Money $amount,
        public string $token,
        public string $description,
        public string $reference,
        public int $clientId,
        public ?int $invoiceId = null,
    ) {}
}
