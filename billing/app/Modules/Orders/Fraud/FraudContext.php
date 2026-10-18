<?php

namespace App\Modules\Orders\Fraud;

/**
 * What a fraud provider may look at for one checkout. Checkout screens before the order exists (no order
 * or, for an inline registration, client id yet); staff re-runs pass the stored order.
 */
final readonly class FraudContext
{
    /**
     * @param  int  $timeoutMs  drivers calling an external service must not wait longer (orders.fraud.timeout_ms)
     */
    public function __construct(
        public ?int $orderId,
        public ?int $clientId,
        public string $email,
        public ?string $ipAddress,
        public ?string $country,
        public int $total,
        public string $currency,
        public int $timeoutMs = 5000,
    ) {}

    /**
     * Whether the order being screened is already stored (and therefore counted by queries).
     */
    public function isStored(): bool
    {
        return $this->orderId !== null;
    }
}
