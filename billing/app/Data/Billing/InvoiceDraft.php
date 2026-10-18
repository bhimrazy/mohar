<?php

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

final readonly class InvoiceDraft
{
    /**
     * @param  list<InvoiceLineData>  $lines
     * @param  'unpaid'|'draft'  $status
     * @param  'order'|'renewal'|'upgrade'|'manual'|null  $reason  why the invoice is raised; renewal drafts are split
     *                                                             per item when the client has separate invoices
     */
    public function __construct(
        public int $clientId,
        public string $currency,
        public array $lines,
        public ?CarbonImmutable $dueDate = null,
        public ?CarbonImmutable $issueDate = null,
        public string $status = 'unpaid',
        public ?string $paymentGateway = null,
        public ?string $notes = null,
        public bool $notify = true,
        public bool $applyCredit = false,
        public ?string $reason = null,
    ) {}
}
