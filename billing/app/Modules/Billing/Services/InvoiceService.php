<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\Invoicer;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Data\Billing\InvoiceResult;
use App\Enums\BillableType;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\IssueInvoice;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Support\BillingSettings;
use App\Support\Billing\InvoiceNotCancellable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Invoicer contract other modules use to create and cancel invoices.
 */
class InvoiceService implements Invoicer
{
    public function __construct(
        private IssueInvoice $issue,
        private CancelInvoice $cancel,
        private BillingSettings $settings,
    ) {}

    /**
     * Renewal lines raised together are grouped on one invoice per client and due date. When the client (or the
     * billing.separate_invoices default) wants separate invoices, a renewal draft is split into one invoice per
     * billable item (promotion lines stay with the item they discount); the first invoice is returned.
     */
    public function issue(InvoiceDraft $draft): InvoiceResult
    {
        if ($draft->reason === 'renewal' && $this->separateInvoices($draft->clientId)) {
            $groups = $this->groupByItem($draft->lines);

            if (count($groups) > 1) {
                return DB::transaction(function () use ($draft, $groups): InvoiceResult {
                    $results = array_map(fn (array $lines): InvoiceResult => $this->issueOne(new InvoiceDraft(
                        $draft->clientId, $draft->currency, $lines, $draft->dueDate, $draft->issueDate, $draft->status,
                        $draft->paymentGateway, $draft->notes, $draft->notify, $draft->applyCredit, $draft->reason,
                    )), $groups);

                    return $results[0];
                });
            }
        }

        return $this->issueOne($draft);
    }

    private function issueOne(InvoiceDraft $draft): InvoiceResult
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

    public function cancel(int $invoiceId, ?string $reason = null, bool $notifyClient = true): void
    {
        $invoice = Invoice::query()->find($invoiceId);

        if ($invoice === null) {
            return;
        }

        try {
            $this->cancel->handle($invoice, note: $reason, strict: false, notifyClient: $notifyClient);
        } catch (BillingException $exception) {
            // The invoice stays payable. Staff see it on their list, and the caller is told so it keeps its own record open.
            $refusals = app(NoteRefusals::class);
            $refusals->record($invoice, InvoiceFiscalReview::CANCEL_REFUSED, $exception->getMessage());

            // A caller that lets the refusal roll its own transaction back (an order cancel) must not lose the record with it.
            DB::afterRollBack(fn () => $refusals->record($invoice, InvoiceFiscalReview::CANCEL_REFUSED, $exception->getMessage()));

            throw new InvoiceNotCancellable($exception->getMessage(), previous: $exception);
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

    private function separateInvoices(int $clientId): bool
    {
        $override = ClientFlag::query()->whereKey($clientId)->value('separate_invoices');

        return $override !== null ? (bool) $override : $this->settings->bool('separate_invoices');
    }

    /**
     * @param  list<InvoiceLineData>  $lines
     * @return list<list<InvoiceLineData>>
     */
    private function groupByItem(array $lines): array
    {
        $groups = [];
        $current = -1;

        foreach ($lines as $line) {
            $startsGroup = $current === -1 || ($line->billableId !== null && $line->billableType !== BillableType::Promotion);
            $startsGroup ? $groups[++$current] = [$line] : $groups[$current][] = $line;
        }

        return array_values($groups);
    }

    /**
     * Why the invoice was created (stored for reporting; Invoicer callers are orders, renewals and upgrades).
     */
    private function source(InvoiceDraft $draft): string
    {
        $types = array_map(fn ($line) => $line->billableType, $draft->lines);

        if ($draft->reason !== null) {
            return mb_substr($draft->reason, 0, 20);
        }

        return match (true) {
            in_array(BillableType::ServiceUpgrade, $types, true) => 'upgrade',
            in_array(BillableType::AddFunds, $types, true) => 'add_funds',
            default => 'system',
        };
    }
}
