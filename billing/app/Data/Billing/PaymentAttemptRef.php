<?php

namespace App\Data\Billing;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The stored attempt a driver looks up (drivers never load models). $interactive is true when a customer's browser
 * is waiting on the lookup (the return route): drivers then make one quick try and leave retries to the poller.
 */
final readonly class PaymentAttemptRef
{
    public function __construct(
        public string $reference,
        public Money $amount,
        public ?string $providerRef = null,
        public ?string $providerTxnId = null,
        public ?CarbonImmutable $expiresAt = null,
        public bool $interactive = false,
    ) {}
}
