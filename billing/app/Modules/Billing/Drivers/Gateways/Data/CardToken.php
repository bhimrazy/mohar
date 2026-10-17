<?php

namespace App\Modules\Billing\Drivers\Gateways\Data;

/**
 * Display details of a gateway token. The card number itself never reaches our database.
 */
final readonly class CardToken
{
    public function __construct(
        public string $token,
        public string $brand,
        public string $last4,
        public int $expiryMonth,
        public int $expiryYear,
    ) {}
}
