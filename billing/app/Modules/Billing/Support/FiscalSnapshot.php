<?php

namespace App\Modules\Billing\Support;

/**
 * The tax-invoice value of an invoice at the moment it is numbered (ADR 0024 §2): taxable, exempt and the two taxes,
 * which add up to the total, plus the entries it adds to the staff-review list. Made by FiscalSnapshotCalculator.
 */
final readonly class FiscalSnapshot
{
    /**
     * @param  list<array{reason: string, subject: string, detail: string}>  $reviews
     */
    public function __construct(
        public int $taxable,
        public int $exempt,
        public int $tax1,
        public int $tax2,
        public int $total,
        public array $reviews = [],
    ) {}

    /** "taxable" when the supply carried tax, else "exempt". No document is an export until the accountant rules on D6. */
    public function supplyClass(): string
    {
        return $this->tax1 + $this->tax2 !== 0 ? 'taxable' : 'exempt';
    }
}
