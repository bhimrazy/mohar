<?php

namespace App\Modules\Billing\Actions;

use App\Events\Billing\PaymentFailed;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Drivers\Gateways\Data\ChargeResult;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayCharger;
use App\Modules\Billing\Support\BillingSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Pay several invoices at once (§4.6): the gateway charges the batch total, settlement allocates to invoices
 * in due-date order and any remainder (e.g. an invoice paid separately meanwhile) becomes account credit.
 */
class PaymentBatches
{
    public function __construct(
        private GatewayCharger $charger,
        private ApplyPayment $applyPayment,
        private CreditLedgerService $credit,
        private BillingSettings $settings,
    ) {}

    /**
     * @param  list<int>  $invoiceIds
     */
    public function create(int $clientId, array $invoiceIds): PaymentBatch
    {
        if (! $this->settings->bool('mass_pay_enabled')) {
            throw new BillingException(__('Paying several invoices at once is not available.'));
        }

        $invoices = Invoice::query()->where('client_id', $clientId)->whereIn('id', $invoiceIds)->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->status->isPayable() && $invoice->balance > 0 && $invoice->kind === InvoiceKind::Standard);

        if ($invoices->count() < 1 || $invoices->pluck('currency')->unique()->count() !== 1) {
            throw new BillingException(__('Select unpaid invoices in one currency.'));
        }

        return PaymentBatch::query()->create([
            'client_id' => $clientId,
            'currency' => (string) $invoices->first()?->currency,
            'invoice_ids' => array_values($invoices->sortBy([['due_date', 'asc'], ['id', 'asc']])->pluck('id')->all()),
            'total' => (int) $invoices->sum('balance'),
            'status' => 'pending',
        ]);
    }

    public function pay(PaymentBatch $batch, string $gatewayKey, string $token, ?PayMethod $payMethod = null, ?int $userId = null): ChargeResult
    {
        if ($batch->status !== 'pending') {
            throw new BillingException(__('This payment has already been processed.'));
        }

        $gateway = $this->charger->activeGateway($gatewayKey);
        [$charge, $rate] = $this->charger->chargeAmount($gateway, Money::of($batch->total, $batch->currency));
        $result = $this->charger->charge($gateway, $token, $charge, 'BATCH-'.$batch->id, $batch->client_id);

        if ($result->succeeded() && $result->txnId !== null) {
            $this->settle($batch, $gatewayKey, $result->txnId, $batch->total, $payMethod?->id, $userId, $result->fee);

            return $result;
        }

        PaymentFailed::dispatch(null, $batch->client_id, $gatewayKey, $result->declineReason ?? $result->status);

        return $result->isPending() ? new ChargeResult(ChargeResult::DECLINED, $result->txnId, declineReason: 'pending_not_supported') : $result;
    }

    public function settle(PaymentBatch $batch, string $gateway, string $txnId, int $amount, ?int $payMethodId = null, ?int $userId = null, int $fee = 0): void
    {
        DB::transaction(function () use ($batch, $gateway, $txnId, $amount, $payMethodId, $userId, $fee): void {
            $batch = PaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($batch->status === 'paid') {
                return;
            }

            $remaining = $amount;
            $invoices = Invoice::query()->whereIn('id', $batch->invoice_ids)->orderBy('due_date')->orderBy('id')->get();

            foreach ($invoices as $invoice) {
                $invoice->refresh();

                if ($remaining <= 0 || ! $invoice->status->isPayable() || $invoice->balance <= 0) {
                    continue;
                }

                $portion = min($remaining, $invoice->balance);
                $this->applyPayment->handle($invoice, new PaymentData(
                    amount: $portion,
                    gateway: $gateway,
                    gatewayTxnId: $txnId.'#'.$invoice->id,
                    fee: $remaining === $amount ? $fee : 0,
                    payMethodId: $payMethodId,
                    createdBy: $userId,
                    description: __('Combined payment #:batch', ['batch' => $batch->id]),
                    paymentBatchId: $batch->id,
                ));
                $remaining -= $portion;
            }

            if ($remaining > 0) {
                $transaction = Transaction::query()->create([
                    'client_id' => $batch->client_id,
                    'payment_batch_id' => $batch->id,
                    'gateway' => $gateway,
                    'gateway_txn_id' => $txnId.'#credit',
                    'type' => TransactionType::Payment,
                    'status' => TransactionStatus::Succeeded,
                    'amount_in' => $remaining,
                    'currency' => $batch->currency,
                    'description' => __('Combined payment #:batch: unallocated amount to credit', ['batch' => $batch->id]),
                    'created_by' => $userId,
                    'occurred_at' => now(),
                ]);
                $this->credit->post($batch->client_id, Money::of($remaining, $batch->currency), CreditReason::Overpayment, transactionId: $transaction->id, description: __('Combined payment #:batch: invoice already paid', ['batch' => $batch->id]));
            }

            $batch->update(['status' => 'paid', 'gateway' => $gateway]);
        });
    }
}
