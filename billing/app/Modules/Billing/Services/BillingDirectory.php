<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\InvoiceQueries;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Support\ClientPaymentOptions;

/**
 * Read-only billing facts for other modules (checkout gateway list, invoice payment history).
 */
class BillingDirectory implements GatewayDirectory, InvoiceQueries
{
    public function __construct(
        private GatewayManager $gateways,
        private ClientPaymentOptions $options,
    ) {}

    public function available(string $currency): array
    {
        return array_values($this->gateways->clientGateways()
            ->filter(fn (Gateway $gateway): bool => $this->options->acceptsCurrency($gateway, $currency))
            ->map(fn (Gateway $gateway): array => ['key' => $gateway->key, 'label' => $gateway->display_name])
            ->all());
    }

    public function hasPayments(int $invoiceId): bool
    {
        return Transaction::query()->where('invoice_id', $invoiceId)->exists()
            || Invoice::query()->whereKey($invoiceId)->where('credit_applied', '>', 0)->exists();
    }
}
