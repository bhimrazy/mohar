<?php

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Enums\TransactionType;
use Carbon\CarbonImmutable;

/**
 * A payment to record against an invoice. $amount is in the invoice currency (minor units).
 *
 * $fromGateway marks money a provider has already taken (capture, webhook, lookup): it is always recorded, but
 * when the order's PaymentGuard refuses payment it is held as account credit instead of settling the invoice.
 */
final readonly class PaymentData
{
    /**
     * @param  array<string, mixed>|null  $raw  redacted gateway payload
     */
    public function __construct(
        public int $amount,
        public string $gateway,
        public ?string $gatewayTxnId = null,
        public int $fee = 0,
        public ?int $payMethodId = null,
        public ?array $raw = null,
        public ?int $createdBy = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $description = null,
        public ?int $gatewayAmount = null,
        public ?string $gatewayCurrency = null,
        public ?float $exchangeRate = null,
        public ?int $paymentBatchId = null,
        public TransactionType $type = TransactionType::Payment,
        public bool $fromGateway = false,
    ) {}
}
