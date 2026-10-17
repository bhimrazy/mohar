<?php

namespace App\Data\Services;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;

final readonly class ServiceDraft
{
    /**
     * @param  array<int, int>  $configOptions
     * @param  array<string, mixed>  $customFields
     */
    public function __construct(
        public int $clientId,
        public int $productId,
        public BillingCycle $cycle,
        public string $currency,
        public int $recurringAmount,
        public int $firstPaymentAmount,
        public CarbonImmutable $nextDueDate,
        public ?int $orderId = null,
        public ?string $domain = null,
        public array $configOptions = [],
        public array $customFields = [],
        public ?int $promotionId = null,
    ) {}
}
