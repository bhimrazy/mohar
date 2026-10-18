<?php

namespace App\Modules\Billing\Services\Tax;

final readonly class TaxResult
{
    /**
     * @param  list<int>  $lineTaxes  tax per input line (same order)
     * @param  int  $subtotal  net amount (inclusive mode: gross minus tax)
     */
    public function __construct(
        public array $lineTaxes,
        public int $subtotal,
        public int $tax1,
        public int $tax2,
        public int $total,
    ) {}

    public function tax(): int
    {
        return $this->tax1 + $this->tax2;
    }
}
