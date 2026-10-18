<?php

namespace App\Data\Currencies;

/**
 * One converted price that would change: before is null when the row does not exist yet.
 */
final readonly class PriceChange
{
    public function __construct(
        /** e.g. "Starter hosting" or ".com register". */
        public string $item,
        public string $currency,
        /** e.g. "Monthly" or "1 year". */
        public string $period,
        public ?int $before,
        public int $after,
    ) {}

    /**
     * @return array{item: string, currency: string, period: string, before: int|null, after: int}
     */
    public function toArray(): array
    {
        return ['item' => $this->item, 'currency' => $this->currency, 'period' => $this->period, 'before' => $this->before, 'after' => $this->after];
    }
}
