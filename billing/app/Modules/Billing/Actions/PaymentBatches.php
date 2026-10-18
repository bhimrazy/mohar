<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Orders\PaymentGuard;
use App\Data\Billing\ChargeResult;
use App\Events\Billing\PaymentFailed;
use App\Modules\Billing\Data\PaymentData;
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
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Billing\Support\BillingSettings;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pay several invoices at once (§4.6): the gateway charges the batch total once (locked, batch moved to
 * `processing` before charging), settlement allocates to invoices in due-date order and any remainder (e.g. an
 * invoice paid separately meanwhile) becomes account credit. The provider's real charge id is kept on the batch.
 */
class PaymentBatches
{
    public function __construct(
        private GatewayCharger $charger,
        private ApplyPayment $applyPayment,
        private CreditLedgerService $credit,
        private BillingSettings $settings,
        private PaymentGuard $guard,
        private GatewayLogger $log,
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

        $this->assertAllowed($invoices->pluck('id')->all());

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
        $lock = Cache::lock('billing.batch.'.$batch->id, 120);

        if (! $lock->get()) {
            throw new BillingException(__('This payment is already in progress.'));
        }

        try {
            $gateway = $this->charger->activeGateway($gatewayKey);

            $batch = DB::transaction(function () use ($batch, $gatewayKey): PaymentBatch {
                $batch = PaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);

                if ($batch->status !== 'pending') {
                    throw new BillingException(__('This payment has already been processed.'));
                }

                $this->assertAllowed($batch->invoice_ids);
                $batch->update(['status' => 'processing', 'gateway' => $gatewayKey]);

                return $batch;
            });

            [$charge, $rate] = $this->charger->chargeAmount($gateway, Money::of($batch->total, $batch->currency));
            $result = $this->charger->charge($gateway, $token, $charge, 'BATCH-'.$batch->id, $batch->client_id);

            if ($result->succeeded() && $result->txnId !== null) {
                $this->settle($batch, $gatewayKey, $result->txnId, $batch->total, $payMethod?->id, $userId, $result->fee, $rate !== null ? $charge : null, $rate);

                return $result;
            }

            $batch->update(['status' => 'pending']);
            PaymentFailed::dispatch(null, $batch->client_id, $gatewayKey, $result->declineReason ?? $result->status);

            return $result->isPending() ? new ChargeResult(ChargeResult::DECLINED, $result->txnId, declineReason: 'pending_not_supported') : $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Record a confirmed batch charge. Idempotent per provider txn id; a second, different charge for an already
     * settled batch (double submit, provider retry) is never dropped: it becomes account credit for review.
     */
    public function settle(PaymentBatch $batch, string $gateway, string $txnId, int $amount, ?int $payMethodId = null, ?int $userId = null, int $fee = 0, ?Money $gatewayCharge = null, ?float $rate = null): void
    {
        DB::transaction(function () use ($batch, $gateway, $txnId, $amount, $payMethodId, $userId, $fee, $gatewayCharge, $rate): void {
            $batch = PaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($batch->gateway_txn_id === $txnId) {
                return;
            }

            if ($batch->status === 'paid') {
                $this->recordDuplicate($batch, $gateway, $txnId, $amount, $userId);

                return;
            }

            $batch->update([
                'status' => 'paid',
                'gateway' => $gateway,
                'gateway_txn_id' => $txnId,
                'gateway_amount' => $gatewayCharge?->amount,
                'gateway_currency' => $gatewayCharge?->currency,
                'exchange_rate' => $rate,
                'fee' => $fee,
            ]);

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
                    payMethodId: $payMethodId,
                    createdBy: $userId,
                    description: __('Combined payment #:batch', ['batch' => $batch->id]),
                    paymentBatchId: $batch->id,
                    fromGateway: true,
                ));
                $remaining -= $portion;
            }

            if ($remaining > 0) {
                $this->toCredit($batch, $gateway, $remaining, $userId, __('Combined payment #:batch: invoice already paid', ['batch' => $batch->id]));
            }
        });
    }

    private function recordDuplicate(PaymentBatch $batch, string $gateway, string $txnId, int $amount, ?int $userId): void
    {
        $exists = Transaction::query()->where('gateway', $gateway)->where('gateway_txn_id', $txnId)->where('type', TransactionType::Payment)->exists();

        if ($exists) {
            return;
        }

        $transaction = $this->toCredit($batch, $gateway, $amount, $userId, __('Duplicate charge for combined payment #:batch (review)', ['batch' => $batch->id]), $txnId);
        $this->log->log($gateway, 'response', 'duplicate batch charge', ['batch' => $batch->id, 'txn_id' => $txnId, 'transaction' => $transaction->id]);
    }

    private function toCredit(PaymentBatch $batch, string $gateway, int $amount, ?int $userId, string $description, ?string $txnId = null): Transaction
    {
        $transaction = Transaction::query()->create([
            'client_id' => $batch->client_id,
            'payment_batch_id' => $batch->id,
            'gateway' => $gateway,
            'gateway_txn_id' => $txnId,
            'type' => TransactionType::Payment,
            'status' => TransactionStatus::Succeeded,
            'amount_in' => $amount,
            'currency' => $batch->currency,
            'description' => $description,
            'created_by' => $userId,
            'occurred_at' => now(),
        ]);
        $this->credit->post($batch->client_id, Money::of($amount, $batch->currency), CreditReason::Overpayment, transactionId: $transaction->id, description: $description);

        return $transaction;
    }

    /**
     * @param  array<int, int>  $invoiceIds
     */
    private function assertAllowed(array $invoiceIds): void
    {
        foreach ($invoiceIds as $invoiceId) {
            if (($reason = $this->guard->refusalReason((int) $invoiceId)) !== null) {
                throw new BillingException($reason);
            }
        }
    }
}
