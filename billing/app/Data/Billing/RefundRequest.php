<?php

namespace App\Data\Billing;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Refund (part of) a provider transaction.
 */
final readonly class RefundRequest
{
    /**
     * @param  array<string, mixed>  $paymentRaw  stored (redacted) payload of the original payment, e.g. payer mobile
     * @param  string|null  $reference  our refund's own id (the pending refund transaction): drivers derive their
     *                                  idempotency key from it and may send it to the provider for reconciliation
     * @param  CarbonImmutable|null  $sentAt  when this refund was first sent: a lookup uses it to tell whether the
     *                                        provider's idempotency window has passed
     */
    public function __construct(
        public string $providerTxnId,
        public Money $amount,
        public Money $originalAmount,
        public array $paymentRaw = [],
        public ?string $reference = null,
        public ?CarbonImmutable $sentAt = null,
    ) {}

    public function isPartial(): bool
    {
        return $this->amount->lessThan($this->originalAmount);
    }
}
