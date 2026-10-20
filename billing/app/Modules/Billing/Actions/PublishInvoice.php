<?php

namespace App\Modules\Billing\Actions;

use App\Enums\BillableType;
use App\Events\Billing\InvoiceCreated;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\DocumentNumbers;
use App\Modules\Billing\Services\InvoiceCalculator;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Services\SellerDetails;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

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
        private SellerDetails $seller,
    ) {}

    /**
     * @param  Client|null  $client  the invoice's client, read in the caller's transaction. Used only when $invoice was
     *                               created in this request (IssueInvoice creates and publishes in one transaction);
     *                               for any other invoice the client is read again, so a copy read earlier can never
     *                               set the tax. A client that is not the invoice's, or is modified, throws.
     *
     * @throws LogicException when $client is not the invoice's unmodified client
     */
    public function handle(Invoice $invoice, ?CarbonImmutable $issueDate = null, bool $notify = true, ?Client $client = null): Invoice
    {
        if ($client !== null && ($client->id !== $invoice->client_id || $client->isDirty())) {
            throw new LogicException("The client passed to publish invoice {$invoice->id} is not its unmodified client.");
        }

        $client = $invoice->wasRecentlyCreated ? $client : null;

        return DB::transaction(function () use ($invoice, $issueDate, $notify, $client): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new BillingException(__('Only draft invoices can be published.'));
            }

            if (! $invoice->items()->whereNull('cancelled_at')->exists()) {
                throw new BillingException(__('Add at least one line before publishing.'));
            }

            $this->calculator->recalculate($invoice, $client);

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

            $invoice->seller_snapshot = $this->seller->snapshot();
            $sac = $this->settings->string('tax.default_sac');

            if ($sac !== '') {
                $invoice->items()->whereNull('sac_code')->where('type', '!=', BillableType::AddFunds)->update(['sac_code' => mb_substr($sac, 0, 10)]);
            }

            $invoice->status = InvoiceStatus::Unpaid;
            $invoice->recalculateBalance();
            $invoice->save();

            AfterCommit::dispatch(new InvoiceCreated($invoice->id, $invoice->client_id, $notify, $invoice->source));
            $this->settlement->settle($invoice);

            return $invoice;
        });
    }
}
