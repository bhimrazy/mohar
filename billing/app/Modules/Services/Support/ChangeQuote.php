<?php

namespace App\Modules\Services\Support;

use App\Enums\BillingCycle;
use App\Modules\Services\Enums\ChangeKind;
use Carbon\CarbonImmutable;

/**
 * Priced preview of an upgrade/downgrade, before it is ordered.
 */
final readonly class ChangeQuote
{
    public function __construct(
        public ChangeKind $kind,
        public int $toProductId,
        public string $toProductName,
        public BillingCycle $toCycle,
        public int $newRecurringAmount,
        public string $currency,
        public Proration $proration,
        public ?CarbonImmutable $nextDueDate,
        public ?int $anchorDay,
    ) {}

    public function amountDue(): int
    {
        return $this->proration->amountDue();
    }

    /**
     * @return array{product_id: int, product_name: string, cycle: string, recurring_amount: int, currency: string, credit: int, charge: int, amount_due: int, next_due_date: ?string, remaining_days: int, cycle_days: int}
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->toProductId,
            'product_name' => $this->toProductName,
            'cycle' => $this->toCycle->value,
            'recurring_amount' => $this->newRecurringAmount,
            'currency' => $this->currency,
            'credit' => $this->proration->credit,
            'charge' => $this->proration->charge,
            'amount_due' => $this->amountDue(),
            'next_due_date' => $this->nextDueDate?->toDateString(),
            'remaining_days' => $this->proration->remainingDays,
            'cycle_days' => $this->proration->cycleDays,
        ];
    }
}
