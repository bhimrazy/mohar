<?php

namespace App\Modules\Orders\Fraud;

/**
 * What a fraud provider may look at for one order.
 */
final readonly class FraudContext
{
    public function __construct(
        public int $orderId,
        public int $clientId,
        public string $email,
        public ?string $ipAddress,
        public ?string $country,
        public int $total,
        public string $currency,
    ) {}
}
