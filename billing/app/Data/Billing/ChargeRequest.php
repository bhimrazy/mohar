<?php

namespace App\Data\Billing;

use App\Support\Money;

/**
 * Charge a stored/one-off token (merchant gateways). Amount is in the currency the gateway charges.
 */
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
