<?php

namespace App\Contracts\Billing;

use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceResult;
use App\Enums\BillableType;

/**
 * Implemented by the Billing module. The only way other modules create invoices.
 */
interface Invoicer
{
    /**
     * Create an invoice (tax applied per client location/exemption). Dispatches InvoiceCreated.
     * A zero-total unpaid invoice is immediately marked paid (and InvoicePaid dispatched).
     */
    public function issue(InvoiceDraft $draft): InvoiceResult;

    /**
     * Cancel an unpaid/draft invoice. Dispatches InvoiceCancelled. No-op if already paid/cancelled.
     */
    public function cancel(int $invoiceId): void;

    /**
     * Whether an unpaid or draft invoice already contains a line for this billable item.
     */
    public function hasOpenInvoiceFor(BillableType $type, int $billableId): bool;

    /**
     * Status of an invoice: draft|unpaid|paid|cancelled|refunded|collections, or null if missing.
     */
    public function status(int $invoiceId): ?string;
}
