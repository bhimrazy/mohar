<?php

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

/**
 * A verified, parsed gateway webhook (push gateways: Stripe, Razorpay, sandbox).
 */
final readonly class CallbackEvent
{
    public const PAYMENT_SUCCEEDED = 'payment_succeeded';

    public const PAYMENT_FAILED = 'payment_failed';

    public const REFUND = 'refund';

    public const CHARGEBACK = 'chargeback';

    /**
     * @param  array<string, mixed>  $raw  payload (redacted before storage)
     * @param  CarbonImmutable|null  $sentAt  provider's (signed) send time, when the protocol has one: Billing rejects
     *                                        callbacks more than 5 minutes old (replay protection)
     */
    public function __construct(
        public string $type,
        public ?int $invoiceId,
        public string $txnId,
        public int $amount,
        public string $currency,
        public ?string $parentTxnId = null,
        public ?string $reason = null,
        public array $raw = [],
        public ?CarbonImmutable $sentAt = null,
    ) {}
}
