<?php

namespace App\Data\Billing;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The stored attempt a driver looks up (drivers never load models).
 */
final readonly class PaymentAttemptRef
{
    public function __construct(
        public string $reference,
        public Money $amount,
        public ?string $providerRef = null,
        public ?string $providerTxnId = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
