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

    /**
     * The provider reports a refund as failed or cancelled: the money did not leave. $txnId is the provider refund id;
     * $reference, $amount, $currency and $parentTxnId as for REFUND. Billing never books or reverses money from it: a
     * refund it already booked goes to staff review, anything else is acknowledged unchanged.
     */
    public const REFUND_FAILED = 'refund_failed';

    public const CHARGEBACK = 'chargeback';

    /**
     * The merchant won a dispute and the provider returned the disputed money. $txnId is the dispute id the CHARGEBACK
     * carried; Billing reverses what that chargeback booked, once per dispute id.
     */
    public const DISPUTE_WON = 'dispute_won';

    /**
     * A dispute the provider opened without withdrawing money (an inquiry, #212). $txnId is the dispute id; Billing
     * puts it on the staff to-do and moves no money. A later CHARGEBACK with the same id books the money.
     */
    public const DISPUTE_INQUIRY = 'dispute_inquiry';

    /**
     * A hosted payment attempt changed at the provider. $reference names our attempt; Billing settles it through
     * HostedPaymentDriver::lookup() and the attempt's stored amount and rate, never from the event's figures.
     */
    public const ATTEMPT_UPDATED = 'attempt_updated';

    /**
     * @param  array<string, mixed>  $raw  payload (redacted before storage)
     * @param  CarbonImmutable|null  $sentAt  provider's (signed) send time, when the protocol has one: Billing rejects
     *                                        callbacks more than 5 minutes old (replay protection)
     * @param  string|null  $reference  our own reference: the payment attempt (ATTEMPT_UPDATED), or the refund
     *                                  transaction a REFUND was started from (RefundRequest::$reference)
     * @param  bool  $fundsReinstated  DISPUTE_WON only: the provider's own record shows the disputed money both
     *                                 withdrawn and reinstated, so the win is final there. A win for which no
     *                                 chargeback was booked is then acknowledged with nothing to record (#232),
     *                                 instead of being refused until a chargeback that will never come
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
        public ?string $reference = null,
        public bool $fundsReinstated = false,
    ) {}

    /**
     * A hosted attempt to settle by lookup (amount and currency stay 0 and empty: they are never used).
     *
     * @param  array<string, mixed>  $raw
     */
    public static function attemptUpdated(string $reference, string $providerObjectId, array $raw = [], ?CarbonImmutable $sentAt = null): self
    {
        return new self(self::ATTEMPT_UPDATED, null, $providerObjectId, 0, '', raw: $raw, sentAt: $sentAt, reference: $reference);
    }
}
