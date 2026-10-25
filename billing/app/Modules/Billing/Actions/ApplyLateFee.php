<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Core\CompanyClock;
use App\Enums\BillableType;
use App\Events\Billing\InvoiceModified;
use App\Events\Billing\LateFeeApplied;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Services\InvoiceCalculator;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Services\NoteRefusals;
use App\Modules\Billing\Services\Tax\TaxEngine;
use App\Modules\Billing\Services\Tax\TaxMath;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Billing\Support\BillingSettings;
use Illuminate\Support\Facades\DB;

/**
 * Add the configured late fee once per invoice as a debit note (§7.1), taxed with the invoice's snapshot rates
 * when late fees are a taxed item kind.
 */
class ApplyLateFee
{
    public function __construct(
        private InvoiceSettlement $settlement,
        private InvoiceCalculator $calculator,
        private TaxEngine $tax,
        private BillingSettings $settings,
        private TaxNoteIssuer $notes,
        private NepalVatMode $mode,
        private CompanyClock $clock,
        private NoteRefusals $refusals,
    ) {}

    /**
     * The fee for an invoice under current settings (0 when off).
     */
    public function feeFor(Invoice $invoice): int
    {
        if (! self::enabled($this->settings)) {
            return 0;
        }

        $base = $this->settings->string('late_fee.base', 'balance') === 'total' ? $invoice->total : $invoice->balance;
        $fee = $this->settings->string('late_fee.type', 'percent') === 'fixed'
            ? $this->settings->int('late_fee.fixed_amount')
            : TaxMath::roundDiv($base * (int) round($this->settings->float('late_fee.amount') * 1000), 100_000);

        return max($fee, $this->settings->int('late_fee.minimum'));
    }

    /**
     * Whether the chosen late fee type has an amount: the percent for "percent", the fixed amount for "fixed".
     */
    public static function enabled(BillingSettings $settings): bool
    {
        return $settings->string('late_fee.type', 'percent') === 'fixed'
            ? $settings->int('late_fee.fixed_amount') > 0
            : $settings->float('late_fee.amount') > 0;
    }

    public function handle(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice): bool {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== InvoiceStatus::Unpaid || $invoice->kind === InvoiceKind::AddFunds || $invoice->late_fee_applied_at !== null || $invoice->balance <= 0) {
                return false;
            }

            $fee = $this->feeFor($invoice);

            if ($fee <= 0) {
                return false;
            }

            $taxable = $tax = 0;
            $rates = $this->calculator->snapshotRates($invoice);

            if ($rates->hasTax() && $this->tax->isTaxable(BillableType::LateFee, true)) {
                [$net, $tax1, $tax2] = TaxMath::split($fee, $rates);
                $taxable = $net;
                $tax = $tax1 + $tax2;
                $fee = $net + $tax;
            }

            // One instant for every row this transaction writes, taken after the lock; the mode is read under a shared lock.
            $at = $this->clock->now()->utc();

            if ($invoice->numbered_at !== null && $this->mode->inEffectLocked($at)) {
                // A numbered tax invoice takes a debit note, not a bare adjustment (vat-register §5.4). When the note is refused
                // (the debit cap, an unclassified invoice) no fee is added, and the invoice goes on the staff list with the reason.
                try {
                    $this->notes->issueNow(NoteRequest::debit($invoice->id, $fee, $taxable > 0, 'late_fee', __('Late payment fee'), automatic: true), $at);
                } catch (BillingException $exception) {
                    $this->refusals->record($invoice, InvoiceFiscalReview::LATE_FEE_REFUSED, $exception->getMessage());

                    return false;
                }

                $invoice->refresh();
                $invoice->late_fee_applied_at = $at;
                $invoice->save();
            } else {
                $invoice->late_fee_applied_at = $at;
                $this->settlement->adjust($invoice, AdjustmentKind::Debit, AdjustmentReason::LateFee, $fee, __('Late payment fee'), taxableAmount: $taxable, taxAmount: $tax, at: $at);
            }

            AfterCommit::dispatch(
                new LateFeeApplied($invoice->id, $invoice->client_id, $fee, $invoice->currency),
                new InvoiceModified($invoice->id, $invoice->client_id),
            );

            return true;
        });
    }
}
