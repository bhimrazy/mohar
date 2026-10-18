<?php

namespace App\Modules\Billing\Actions;

use App\Events\Billing\InvoiceModified;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoiceSettlement;
use App\Modules\Billing\Support\AfterCommit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Return applied credit from an unpaid invoice to the client's balance (debit note + ledger credit).
 */
class RemoveCredit
{
    public function __construct(
        private InvoiceSettlement $settlement,
        private CreditLedgerService $credit,
    ) {}

    public function handle(Invoice $invoice, int $amount, ?int $userId = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $amount, $userId): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->status->isPayable()) {
                throw new BillingException(__('Credit can only be removed from unpaid invoices.'));
            }

            if ($amount <= 0 || $amount > $invoice->credit_applied) {
                throw new BillingException(__('Enter an amount up to the credit applied to this invoice.'));
            }

            $invoice->credit_applied -= $amount;
            $this->settlement->adjust($invoice, AdjustmentKind::Debit, AdjustmentReason::CreditRemoved, $amount, __('Account credit removed'), userId: $userId);
            $this->credit->post($invoice->client_id, Money::of($amount, $invoice->currency), CreditReason::CreditRemoved, $invoice->id, description: __('Removed from invoice :number', ['number' => $invoice->displayNumber()]), staffId: $userId);

            AfterCommit::dispatch(new InvoiceModified($invoice->id, $invoice->client_id));

            return $invoice;
        });
    }
}
