<?php

namespace App\Modules\Billing\Actions;

use App\Enums\BillableType;
use App\Events\Billing\InvoiceModified;
use App\Events\Billing\LateFeeApplied;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoiceCalculator;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Services\Tax\TaxEngine;
use App\Modules\Billing\Services\Tax\TaxMath;
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
    ) {}

    /**
     * The fee for an invoice under current settings (0 when off).
     */
    public function feeFor(Invoice $invoice): int
    {
        $amount = $this->settings->float('late_fee.amount');

        if ($amount <= 0) {
            return 0;
        }

        $base = $this->settings->string('late_fee.base', 'balance') === 'total' ? $invoice->total : $invoice->balance;
        $fee = $this->settings->string('late_fee.type', 'percent') === 'fixed'
            ? (int) round($amount)
            : TaxMath::roundDiv($base * (int) round($amount * 1000), 100_000);

        return max($fee, $this->settings->int('late_fee.minimum'));
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

            $invoice->late_fee_applied_at = now();
            $this->settlement->adjust($invoice, AdjustmentKind::Debit, AdjustmentReason::LateFee, $fee, __('Late payment fee'), taxableAmount: $taxable, taxAmount: $tax);

            AfterCommit::dispatch(
                new LateFeeApplied($invoice->id, $invoice->client_id, $fee, $invoice->currency),
                new InvoiceModified($invoice->id, $invoice->client_id),
            );

            return true;
        });
    }
}
