<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceResult;
use App\Enums\BillableType;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\IssueInvoice;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use Carbon\CarbonImmutable;

/**
 * The Invoicer contract other modules use to create and cancel invoices.
 */
class InvoiceService implements Invoicer
{
    public function __construct(
        private IssueInvoice $issue,
        private CancelInvoice $cancel,
    ) {}

    public function issue(InvoiceDraft $draft): InvoiceResult
    {
        $invoice = $this->issue->handle($draft, $this->source($draft));

        return new InvoiceResult(
            invoiceId: $invoice->id,
            number: $invoice->number ?? $invoice->proforma_number ?? '',
            status: $invoice->status->value,
            currency: $invoice->currency,
            subtotal: $invoice->subtotal,
            tax: $invoice->tax1_amount + $invoice->tax2_amount,
            total: $invoice->total,
        );
    }

    public function cancel(int $invoiceId): void
    {
        $invoice = Invoice::query()->find($invoiceId);

        if ($invoice !== null) {
            $this->cancel->handle($invoice, strict: false);
        }
    }

    public function hasOpenInvoiceFor(BillableType $type, int $billableId): bool
    {
        return InvoiceItem::query()
            ->where('type', $type)
            ->where('billable_id', $billableId)
            ->whereNull('cancelled_at')
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [InvoiceStatus::Draft, InvoiceStatus::Unpaid, InvoiceStatus::PaymentPending]))
            ->exists();
    }

    public function hasInvoiceForPeriod(BillableType $type, int $billableId, CarbonImmutable $periodStart): bool
    {
        return InvoiceItem::query()
            ->where('type', $type)
            ->where('billable_id', $billableId)
            ->whereDate('period_start', $periodStart->toDateString())
            ->whereNull('cancelled_at')
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', InvoiceStatus::Cancelled))
            ->exists();
    }

    public function status(int $invoiceId): ?string
    {
        return Invoice::query()->whereKey($invoiceId)->first()?->status->value;
    }

    /**
     * Why the invoice was created (stored for reporting; Invoicer callers are orders, renewals and upgrades).
     */
    private function source(InvoiceDraft $draft): string
    {
        $types = array_map(fn ($line) => $line->billableType, $draft->lines);

        return match (true) {
            in_array(BillableType::ServiceUpgrade, $types, true) => 'upgrade',
            in_array(BillableType::AddFunds, $types, true) => 'add_funds',
            default => 'system',
        };
    }
}
