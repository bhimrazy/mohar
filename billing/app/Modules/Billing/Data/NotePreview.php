<?php

namespace App\Modules\Billing\Data;

/**
 * A planned note: the allocation, the figures and the balance after it. `version` and `digest` bind the confirm to
 * exactly this plan (ADR 0021 §1).
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
    ) {}
}
