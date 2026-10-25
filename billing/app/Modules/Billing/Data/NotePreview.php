<?php

namespace App\Modules\Billing\Data;

/**
 * A planned note: the allocation, the figures and the balance after it. `toCredit` is what goes to account credit: the
 * part of a credit note above the open balance, or, for a reversal, the credit its credit note posted, taken back
 * (`settlement` says which). `dueDate` is the new due date when the note leaves a paid invoice owing again; `creditAfter`
 * is the client's account credit after a reversal, which may be negative. `version` and `digest` bind the confirm to exactly this plan (ADR 0021 §1).
 *
 * @phpstan-import-type Part from \App\Modules\Billing\Support\NoteAllocator
 */
final readonly class NotePreview
{
    /**
     * @param  list<Part>  $parts
     */
    public function __construct(
        public string $kind,
        public array $parts,
        public int $total,
        public int $taxable,
        public int $exempt,
        public int $tax1,
        public int $tax2,
        public int $balanceAfter,
        public int $version,
        public string $digest,
        public string $settlement = 'balance',
        public int $toCredit = 0,
        public ?string $dueDate = null,
        public ?int $creditAfter = null,
    ) {}
}
