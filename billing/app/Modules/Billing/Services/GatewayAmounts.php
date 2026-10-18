<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\Tax\TaxMath;
use App\Support\Money;

/**
 * Converts between the document (invoice) currency of a payment and what the provider actually charged, using the
 * amounts recorded at payment time (prorated, integer maths) — never today's exchange rate. Also resolves the
 * provider's real charge id for payments allocated from a combined (batch) charge.
 */
class GatewayAmounts
{
    /**
     * @return array{txnId: string|null, gatewayAmount: int, gatewayCurrency: string, documentAmount: int}
     */
    public function charge(Transaction $payment): array
    {
        if ($payment->gateway_txn_id === null && $payment->payment_batch_id !== null) {
            $batch = PaymentBatch::query()->find($payment->payment_batch_id);

            if ($batch !== null) {
                return [
                    'txnId' => $batch->gateway_txn_id,
                    'gatewayAmount' => $batch->gateway_amount ?? $batch->total,
                    'gatewayCurrency' => $batch->gateway_currency ?? $batch->currency,
                    'documentAmount' => $batch->total,
                ];
            }
        }

        return [
            'txnId' => $payment->gateway_txn_id,
            'gatewayAmount' => $payment->gateway_amount ?? $payment->amount_in,
            'gatewayCurrency' => $payment->gateway_currency ?? $payment->currency,
            'documentAmount' => $payment->amount_in,
        ];
    }

    /**
     * Document-currency amount → what to send the provider (e.g. a USD refund of a payment Khalti took in NPR).
     */
    public function toGateway(Transaction $payment, int $documentAmount): Money
    {
        $charge = $this->charge($payment);

        if ($charge['gatewayCurrency'] === $payment->currency || $charge['documentAmount'] === 0) {
            return Money::of($documentAmount, $charge['gatewayCurrency']);
        }

        return Money::of(TaxMath::roundDiv($documentAmount * $charge['gatewayAmount'], $charge['documentAmount']), $charge['gatewayCurrency']);
    }

    /**
     * An amount reported by the provider (refund/chargeback webhook) → document-currency minor units.
     */
    public function toDocument(Transaction $payment, int $amount, string $currency): int
    {
        $currency = strtoupper($currency);

        if ($currency === $payment->currency) {
            return $amount;
        }

        $charge = $this->charge($payment);

        if ($currency !== $charge['gatewayCurrency'] || $charge['gatewayAmount'] === 0) {
            throw new BillingException(__('Currency mismatch: :currency is neither the invoice nor the charged currency.', ['currency' => $currency]));
        }

        return TaxMath::roundDiv($amount * $charge['documentAmount'], $charge['gatewayAmount']);
    }
}
