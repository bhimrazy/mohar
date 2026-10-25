<?php

namespace App\Data\Billing;

/**
 * Authoritative status from the provider's status API.
 */
final readonly class LookupResult
{
    public const SUCCEEDED = 'succeeded';

    public const PENDING = 'pending';

    /**
     * The customer has paid and the provider is still settling (e.g. a card or bank payment "processing"). The
     * attempt stays open and keeps blocking new payments for the invoice past its session expiry, until it resolves.
     */
    public const PROCESSING = 'processing';

    public const AMBIGUOUS = 'ambiguous';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const NOT_FOUND = 'not_found';

    public const REFUNDED = 'refunded';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUSES = [self::SUCCEEDED, self::PENDING, self::PROCESSING, self::AMBIGUOUS, self::FAILED, self::CANCELLED, self::EXPIRED, self::NOT_FOUND, self::REFUNDED, self::PARTIALLY_REFUNDED];

    /**
     * $amount and $currency are required with SUCCEEDED; without either, Billing holds the attempt for staff review.
     *
     * @param  int|null  $amount  minor units actually paid, as reported by the provider
     * @param  string|null  $currency  ISO 4217 code of what was paid (the attempt's currency), as reported by the provider
     * @param  array<string, mixed>  $raw  response (redacted before storage)
     * @param  bool  $retryable  transient provider error: try again later
     * @param  bool  $sessionEnded  with not_found: the provider says it holds no payable session for this reference
     *                              (e.g. eSewa after a cancel), so Billing may end a pending attempt early
     */
    public function __construct(
        public string $status,
        public ?string $providerTxnId = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public int $fee = 0,
        public ?string $payerRef = null,
        public array $raw = [],
        public bool $retryable = false,
        public bool $sessionEnded = false,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }
}
