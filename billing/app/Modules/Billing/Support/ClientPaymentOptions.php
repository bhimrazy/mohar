<?php

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Drivers\Gateways\GatewayDriver;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Http\Requests\Client\PayInvoiceRequest;
use App\Modules\Billing\Http\Resources\PayMethodResource;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;

/**
 * What a client may pay with, and resolution of a pay request into gateway + token (scoped to the client).
 */
class ClientPaymentOptions
{
    public function __construct(
        private GatewayManager $gateways,
        private BillingSettings $settings,
        private CreditLedgerService $credit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forInvoice(Invoice $invoice): array
    {
        $choose = $this->settings->bool('clients_choose_gateway');
        $gateways = $this->gateways->clientGateways()
            ->filter(fn (Gateway $gateway): bool => $choose || $invoice->payment_gateway === null || $gateway->key === $invoice->payment_gateway);

        return [
            ...$this->options($invoice->client, $gateways->all(), $invoice),
            'selected_gateway' => $invoice->payment_gateway ?? $gateways->first()?->key,
            'credit_available' => $invoice->kind->value === 'add_funds' ? 0 : $this->credit->balance($invoice->client_id, $invoice->currency)->amount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forClient(Client $client, string $currency): array
    {
        $gateways = $this->gateways->clientGateways();

        return [
            ...$this->options($client, $gateways->all(), null),
            'selected_gateway' => $gateways->first(fn (Gateway $gateway): bool => $this->gateways->supports($gateway->key, GatewayDriver::CAPTURE))?->key,
            'credit_available' => 0,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: PayMethod|null}
     */
    public function resolve(Invoice $invoice, PayInvoiceRequest $request): array
    {
        $gateway = $request->string('gateway')->toString();

        if (! $this->settings->bool('clients_choose_gateway') && $invoice->payment_gateway !== null && $gateway !== $invoice->payment_gateway) {
            throw new BillingException(__('This invoice must be paid with :gateway.', ['gateway' => $this->gateways->gateway($invoice->payment_gateway)->display_name]));
        }

        return $this->resolveForClient($invoice->client()->firstOrFail(), $request);
    }

    /**
     * @return array{0: string, 1: string, 2: PayMethod|null}
     */
    public function resolveForClient(Client $client, PayInvoiceRequest $request): array
    {
        $gateway = $request->string('gateway')->toString();

        if (! $this->gateways->clientGateways()->contains('key', $gateway) || ! $this->gateways->supports($gateway, GatewayDriver::CAPTURE)) {
            throw new BillingException(__('Choose an online payment method.'));
        }

        if ($request->filled('pay_method_id')) {
            $method = PayMethod::query()->where('client_id', $client->id)->where('gateway', $gateway)->usable()->find($request->integer('pay_method_id'));

            if ($method === null || ! $method->isUsable()) {
                throw new BillingException(__('That saved card can\'t be used. Choose another card.'));
            }

            return [$gateway, $method->token, $method];
        }

        return [$gateway, $request->string('token')->toString(), null];
    }

    public static function declineMessage(?string $reason): string
    {
        return match ($reason) {
            'card_declined' => __('Payment failed: card declined. Try another card.'),
            'invalid_token' => __('Payment failed: the card details could not be verified. Enter them again.'),
            'pending_not_supported' => __('Payment failed: this card can\'t be used for a combined payment. Pay the invoices one by one.'),
            default => __('Payment failed. Try again or use another payment method.'),
        };
    }

    /**
     * @param  array<int, Gateway>  $gateways
     * @return array<string, mixed>
     */
    private function options(Client $client, array $gateways, ?Invoice $invoice): array
    {
        $methods = PayMethod::query()->where('client_id', $client->id)->usable()->orderByDesc('is_default')->orderBy('id')->get()
            ->filter(fn (PayMethod $method): bool => $method->isUsable())->values();

        return [
            'gateways' => array_values(array_map(function (Gateway $gateway) use ($invoice): array {
                $driver = $this->gateways->driver($gateway->key);

                return [
                    'key' => $gateway->key,
                    'name' => $gateway->display_name,
                    'kind' => $driver->supports(GatewayDriver::CAPTURE) ? 'card' : 'offline',
                    'instructions' => $invoice !== null ? $driver->instructions($gateway, $invoice) : null,
                    'vault_url' => $driver->supports(GatewayDriver::REMOTE_INPUT) ? route('billing.sandbox.tokens') : null,
                ];
            }, $gateways)),
            'can_choose_gateway' => $this->settings->bool('clients_choose_gateway'),
            'pay_methods' => PayMethodResource::collection($methods),
        ];
    }
}
