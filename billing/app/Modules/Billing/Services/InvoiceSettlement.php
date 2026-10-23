<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Core\CompanyClock;
use App\Enums\BillableType;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceAdjustment;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Billing\Support\FiscalSnapshotCalculator;
use App\Support\CalendarDate;
use App\Support\Money;

/**
 * Balance bookkeeping shared by every money action: adjustments (credit/debit notes) and the transition to paid.
 * Callers hold a row lock on the invoice inside a transaction.
 */
class InvoiceSettlement
{
    public function __construct(
        private BillingSettings $settings,
        private DocumentNumbers $numbers,
        private CreditLedgerService $credit,
        private CompanyClock $clock,
        private FiscalSnapshotWriter $fiscal,
    ) {}

    public function adjust(
        Invoice $invoice,
        AdjustmentKind $kind,
        AdjustmentReason $reason,
        int $amount,
        ?string $description = null,
        ?int $transactionId = null,
        ?int $userId = null,
        int $taxableAmount = 0,
        int $taxAmount = 0,
    ): InvoiceAdjustment {
        $adjustment = $invoice->adjustments()->create([
            'kind' => $kind,
            'reason' => $reason,
            'amount' => $amount,
            'taxable_amount' => $taxableAmount,
            'tax_amount' => $taxAmount,
            'description' => $description,
            'transaction_id' => $transactionId,
            'created_by' => $userId,
        ]);

        $invoice->adjustments_total += $adjustment->signedAmount();

        // The value changes after numbering, summed for the caps (ADR 0024 §3); this is the only writer. Rows written
        // before numbering are inside the snapshot instead.
        if ($invoice->numbered_at !== null && in_array($reason->value, FiscalSnapshotCalculator::VALUE_REASONS, true)) {
            $kind === AdjustmentKind::Credit ? $invoice->credited_total += $amount : $invoice->debited_total += $amount;
        }

        $invoice->recalculateBalance();
        $invoice->save();

        return $adjustment;
    }

    /**
     * Move a payable invoice whose balance reached zero to paid: legal number and tax snapshot in proforma mode, add-funds credit,
     * and InvoicePaid (after commit) with the line DTOs. Returns whether the transition happened.
     */
    public function settle(Invoice $invoice): bool
    {
        if (! $invoice->status->isPayable() || $invoice->balance > 0) {
            return false;
        }

        $at = $this->clock->now()->utc();
        $invoice->status = InvoiceStatus::Paid;
        $invoice->paid_at = $at;

        if ($invoice->number === null && $this->settings->bool('sequential_paid_numbering')) {
            $today = $this->clock->today();

            if ($this->settings->bool('set_issue_date_on_payment')) {
                $invoice->issue_date = $today;
            }

            // The snapshot is written once, in this transaction, with every adjustment written so far (ADR 0024 §2),
            // and before the sequence row is locked (§4).
            $this->fiscal->stamp($invoice, $at);
            $invoice->number = $this->numbers->next(DocumentNumbers::INVOICE, CalendarDate::fromDateValue($today));
        }

        $invoice->save();

        if ($invoice->kind === InvoiceKind::AddFunds) {
            $funds = (int) $invoice->items()->where('type', BillableType::AddFunds)->whereNull('cancelled_at')->sum('amount');

            if ($funds > 0) {
                $this->credit->post($invoice->client_id, Money::of($funds, $invoice->currency), CreditReason::AddFunds, $invoice->id, description: __('Funds added via invoice :number', ['number' => $invoice->displayNumber()]));
            }
        }

        $invoice->load('items');
        AfterCommit::dispatch(new InvoicePaid($invoice->id, $invoice->client_id, $invoice->lineData()));

        return true;
    }

    /**
     * Turn a negative balance into account credit with an overpayment debit note, so the balance ends at zero.
     *
     * @return int the amount moved to credit
     */
    public function absorbOverpayment(Invoice $invoice, ?int $transactionId = null): int
    {
        if ($invoice->balance >= 0) {
            return 0;
        }

        $over = -$invoice->balance;
        $this->adjust($invoice, AdjustmentKind::Debit, AdjustmentReason::Overpayment, $over, __('Overpayment moved to account credit'), $transactionId);
        $this->credit->post($invoice->client_id, Money::of($over, $invoice->currency), CreditReason::Overpayment, $invoice->id, $transactionId, __('Overpayment of invoice :number', ['number' => $invoice->displayNumber()]));

        return $over;
    }

    /**
     * Lines may be guarded against double invoicing only while the invoice is not cancelled.
     */
    public function releaseGuards(Invoice $invoice): void
    {
        InvoiceItem::query()->where('invoice_id', $invoice->id)->whereNotNull('period_guard')->update(['period_guard' => null]);
    }
}
