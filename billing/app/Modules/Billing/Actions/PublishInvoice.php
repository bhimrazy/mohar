<?php

namespace App\Modules\Billing\Actions;

use App\Events\Billing\InvoiceCreated;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\DocumentNumbers;
use App\Modules\Billing\Services\InvoiceCalculator;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Billing\Support\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * draft → unpaid: recompute tax with current rules, assign the next number (or proforma number), set issue
 * and due dates, dispatch InvoiceCreated. Zero-total invoices are settled immediately.
 */
class PublishInvoice
{
    public function __construct(
        private InvoiceCalculator $calculator,
        private DocumentNumbers $numbers,
        private InvoiceSettlement $settlement,
        private BillingSettings $settings,
    ) {}

    public function handle(Invoice $invoice, ?CarbonImmutable $issueDate = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $issueDate): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new BillingException(__('Only draft invoices can be published.'));
            }

            if (! $invoice->items()->whereNull('cancelled_at')->exists()) {
                throw new BillingException(__('Add at least one line before publishing.'));
            }

            $this->calculator->recalculate($invoice);

            if ($invoice->total < 0) {
                throw new BillingException(__('An invoice total can\'t be negative. Issue account credit or a credit note instead.'));
            }

            $today = CarbonImmutable::today();
            $invoice->issue_date = $issueDate ?? $today;
            $invoice->due_date ??= $today->addDays($this->settings->int('invoice_days_before_due', 14));

            if ($this->settings->bool('sequential_paid_numbering')) {
                $invoice->proforma_number = $this->numbers->next(DocumentNumbers::PROFORMA, $invoice->issue_date);
            } else {
                $invoice->number = $this->numbers->next(DocumentNumbers::INVOICE, $invoice->issue_date);
            }

            $invoice->status = InvoiceStatus::Unpaid;
            $invoice->recalculateBalance();
            $invoice->save();

            AfterCommit::dispatch(new InvoiceCreated($invoice->id, $invoice->client_id));
            $this->settlement->settle($invoice);

            return $invoice;
        });
    }
}
