<?php

namespace App\Modules\Billing\Drivers\Gateways;

use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;

/**
 * Offline bank transfer: the client sees instructions, staff record the payment when it arrives.
 */
class BankTransferDriver extends AbstractGatewayDriver
{
    public function key(): string
    {
        return 'bank_transfer';
    }

    public function name(): string
    {
        return 'Bank transfer';
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'instructions', 'label' => 'Payment instructions', 'type' => 'text', 'help' => 'Shown on unpaid invoices. {invoice} is replaced with the invoice number.'],
        ];
    }

    public function instructions(Gateway $gateway, Invoice $invoice): ?string
    {
        $text = (string) $gateway->setting('instructions', '');

        return $text === '' ? null : str_replace('{invoice}', $invoice->displayNumber(), $text);
    }
}
