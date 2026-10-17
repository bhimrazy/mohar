<?php

namespace App\Data\Billing;

use App\Enums\BillableType;
use Carbon\CarbonImmutable;

/**
 * One invoice line. Amounts are minor units in the invoice currency.
 */
final readonly class InvoiceLineData
{
    public function __construct(
        public string $description,
        public int $unitAmount,
        public int $quantity = 1,
        public bool $taxable = true,
        public ?BillableType $billableType = null,
        public ?int $billableId = null,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
    ) {}

    public function total(): int
    {
        return $this->unitAmount * $this->quantity;
    }
}
