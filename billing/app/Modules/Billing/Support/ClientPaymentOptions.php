<?php

namespace App\Modules\Billing\Support;

use App\Contracts\Billing\GatewayDriver;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Http\Requests\Client\PayInvoiceRequest;
use App\Modules\Billing\Http\Resources\PayMethodResource;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayCharger;
use App\Modules\Core\Models\Client;
use Illuminate\Support\Facades\Route;

/**
 * What a client may pay with, and resolution of a pay request into gateway + token (scoped to the client).
 */
class ClientPaymentOptions
{
    public function __construct(
        private GatewayManager $gateways,
        private BillingSettings $settings,
        private CreditLedgerService $credit,
        private GatewayCharger $charger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forInvoice(Invoice $invoice): array
    {
        $choose = $this->settings->bool('clients_choose_gateway');
        $gateways = $this->gateways->clientGateways()
            ->filter(fn (Gateway $gateway): bool => $this->acceptsCurrency($gateway, $invoice->currency))
            ->filter(fn (Gateway $gateway): bool => $choose || $invoice->payment_gateway === null || $gateway->key === $invoice->payment_gateway);
        $options = $this->options($invoice->client, $gateways->all(), $invoice);
        $available = array_values(array_filter($options['gateways'], fn (array $option): bool => $option['unavailable_reason'] === null));
        $preferred = $invoice->payment_gateway !== null && in_array($invoice->payment_gateway, array_column($available, 'key'), true) ? $invoice->payment_gateway : null;

        return [
            ...$options,
            'selected_gateway' => $preferred ?? ($available[0]['key'] ?? null),
            'credit_available' => $invoice->kind->value === 'add_funds' ? 0 : $this->credit->balance($invoice->client_id, $invoice->currency)->amount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forClient(Client $client, string $currency): array
    {
        $gateways = $this->gateways->clientGateways()->filter(fn (Gateway $gateway): bool => $this->acceptsCurrency($gateway, $currency));

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

        if (! $this->gateways->clientGateways()->contains('key', $gateway) || $this->kind($gateway) !== 'card') {
            throw new BillingException(__('Choose an online payment method.'));
        }

        if (! $request->filled('pay_method_id') && ! $request->filled('token')) {
            throw new BillingException(__('Choose a saved card or enter a new one.'));
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

    /**
     * Whether a gateway can take a payment in this currency (natively or by converting).
     */
    public function acceptsCurrency(Gateway $gateway, string $currency): bool
    {
        $supported = $this->gateways->driver($gateway->key)->currencies();

        return $supported === null || in_array(strtoupper($currency), $supported, true)
            || ($gateway->convert_to_currency !== null && in_array(strtoupper($gateway->convert_to_currency), $supported, true));
    }

    /**
     * How clients pay with a gateway: inline card form (capture), hosted redirect (HostedPaymentDriver; also when
     * a dual-mode driver is set to checkout_mode=hosted), or offline instructions.
     */
    public function kind(string $gateway): string
    {
        if (! $this->gateways->has($gateway)) {
            return 'offline';
        }

        $hosted = $this->gateways->hosted($gateway) !== null;
        $capture = $this->gateways->supports($gateway, GatewayDriver::CAPTURE);
        $mode = $this->gateways->config($gateway)->setting('checkout_mode', 'inline');

        return match (true) {
            $hosted && (! $capture || $mode === 'hosted') => 'redirect',
            $capture => 'card',
            default => 'offline',
        };
    }

    /**
     * For hosted gateways: whether the charge fits the provider's limits, and the converted charge when the
     * provider settles in another currency. Uses the same calculation as StartHostedPayment.
     *
     * @return array{0: string|null, 1: array{amount: int, currency: string, rate: float, from: string}|null}
     */
    private function hostedCheck(Gateway $gateway, Invoice $invoice): array
    {
        $driver = $this->gateways->hosted($gateway->key);

        if ($driver === null || $this->kind($gateway->key) !== 'redirect') {
            return [null, null];
        }

        try {
            $charge = $this->charger->hostedCharge($gateway, $driver, $invoice->balanceMoney());
        } catch (BillingException $exception) {
            return [$exception->getMessage(), null];
        }

        if ($charge === null) {
            return [__(':gateway can\'t take payments in :currency.', ['gateway' => $gateway->display_name, 'currency' => $invoice->currency]), null];
        }

        [$amount, $rate] = $charge;

        return [
            $this->charger->limitReason($gateway, $driver, $amount),
            $rate !== null ? ['amount' => $amount->amount, 'currency' => $amount->currency, 'rate' => $rate, 'from' => $invoice->currency] : null,
        ];
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
                [$unavailable, $conversion] = $invoice !== null ? $this->hostedCheck($gateway, $invoice) : [null, null];

                return [
                    'key' => $gateway->key,
                    'name' => $gateway->display_name,
                    // The provider the customer is sent to ("Stripe"), whatever staff call the method.
                    'provider' => $driver->name(),
                    'kind' => $this->kind($gateway->key),
                    'instructions' => $invoice !== null ? $driver->instructions($this->gateways->config($gateway), $invoice->displayNumber()) : null,
                    'vault_url' => $driver->supports(GatewayDriver::REMOTE_INPUT) && Route::has('billing.sandbox.tokens') ? route('billing.sandbox.tokens') : null,
                    'sandbox' => $this->gateways->isSandbox($gateway),
                    'logo' => $this->gateways->logo($gateway->key),
                    // With a logo, the staff-set name is still shown when it says more than the provider's name.
                    'show_name' => strcasecmp(trim($gateway->display_name), $driver->name()) !== 0,
                    // Why this method can't take this invoice (e.g. below the provider's minimum); shown, disabled.
                    'unavailable_reason' => $unavailable,
                    // What the customer will actually pay at the provider when it charges in another currency.
                    'conversion' => $conversion,
                ];
            }, $gateways)),
            'can_choose_gateway' => $this->settings->bool('clients_choose_gateway'),
            'pay_methods' => PayMethodResource::collection($methods),
        ];
    }
}
