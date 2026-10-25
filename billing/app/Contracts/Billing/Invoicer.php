<?php

namespace App\Contracts\Billing;

use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceResult;
use App\Enums\BillableType;
use App\Support\Billing\InvoiceNotCancellable;
use Carbon\CarbonImmutable;

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
     *
     * With Nepal VAT mode on, cancelling a numbered tax invoice issues a credit note for its open balance, so a caller can
     * create a legal document; `$reason` is the text printed on it ("Cancelled by Orders"). Callers hold their own row locks
     * before Billing's invoice lock.
     *
     * `$notify` false keeps that credit note from being emailed to the client (a cancel the client did not ask
     * for and need not hear about, such as a fraud flag or a stale unpaid order); the webhook is sent either way.
     *
     * @throws InvoiceNotCancellable when the invoice stays payable (for example its credit note was
     *                               refused). Billing has put it on its staff list; the caller must not mark its own record cancelled.
     */
    public function cancel(int $invoiceId, ?string $reason = null, bool $notify = true): void;

    /**
     * Whether an unpaid or draft invoice already contains a line for this billable item.
     */
    public function hasOpenInvoiceFor(BillableType $type, int $billableId): bool;

    /**
     * Whether any non-cancelled invoice already bills this item for the period starting on $periodStart.
     * Renewal generators MUST check this before issuing (prevents double invoicing on re-runs).
     */
    public function hasInvoiceForPeriod(BillableType $type, int $billableId, CarbonImmutable $periodStart): bool;

    /**
     * Status of an invoice: draft|unpaid|payment_pending|paid|cancelled|refunded|collections, or null if missing.
     */
    public function status(int $invoiceId): ?string;
}
