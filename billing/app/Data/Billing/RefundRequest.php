<?php

namespace App\Data\Billing;

use App\Support\Money;

/**
 * Refund (part of) a provider transaction.
 */
final readonly class RefundRequest
{
    /**
     * @param  array<string, mixed>  $paymentRaw  stored (redacted) payload of the original payment, e.g. payer mobile
     */
    public function __construct(
        public string $providerTxnId,
        public Money $amount,
        public Money $originalAmount,
        public array $paymentRaw = [],
    ) {}

    public function isPartial(): bool
    {
        return $this->amount->lessThan($this->originalAmount);
    }
}
