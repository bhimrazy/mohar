<?php

namespace App\Data\Billing;

/**
 * Outcome of a capture or refund call: success|pending|declined|error.
 */
final readonly class ChargeResult
{
    public const SUCCESS = 'success';

    public const PENDING = 'pending';

    public const DECLINED = 'declined';

    public const ERROR = 'error';

    /**
     * @param  array<string, mixed>  $raw  provider response (redacted before storage)
     */
    public function __construct(
        public string $status,
        public ?string $txnId = null,
        public int $fee = 0,
        public ?string $declineReason = null,
        public array $raw = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
