<?php

namespace App\Data\Billing;

final readonly class TaxTotals
{
    /**
     * @param  list<array{name: string, rate: float, amount: int}>  $taxes
     */
    public function __construct(
        public string $currency,
        public int $subtotal,
        public array $taxes,
        public int $total,
    ) {}

    public function taxAmount(): int
    {
        return array_sum(array_column($this->taxes, 'amount'));
    }
}
