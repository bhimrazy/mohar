<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Data\Billing\ChargeRequest;
use App\Data\Billing\ChargeResult;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\UnsupportedCapabilityException;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Support\Billing\SandboxPolicy;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Money;

/**
 * Charges a token through a gateway, applying the gateway's currency conversion and logging request/response.
 */
class GatewayCharger
{
    public function __construct(
        private GatewayManager $gateways,
        private CurrencyConverter $converter,
        private GatewayLogger $log,
        private MoneyFormatter $formatter,
    ) {}

    public function activeGateway(string $key): Gateway
    {
        $gateway = $this->gateways->gateway($key);

        // A gateway left in sandbox mode never takes payments where test money is not allowed (production).
        if (! $gateway->exists || ! $gateway->is_active || (! SandboxPolicy::allowed() && $this->gateways->isSandbox($gateway))) {
            throw new BillingException(__('This payment method is not available.'));
        }

        return $gateway;
    }

    /**
     * The amount the gateway will actually charge for a balance in the document currency.
     *
     * @return array{0: Money, 1: float|null} charge amount and conversion rate (null when not converted)
     */
    public function chargeAmount(Gateway $gateway, Money $balance): array
    {
        $target = $gateway->convert_to_currency;

        if ($target === null || strtoupper($target) === $balance->currency) {
            return [$balance, null];
        }

        $rate = $this->converter->rate($balance->currency, $target);

        return [Money::of($this->converter->convert($balance->amount, $rate), $target), $rate];
    }

    /**
     * What a hosted driver will collect for a document balance: the balance itself when the driver settles in its
     * currency, else the balance converted to the gateway's convert_to_currency (null when neither applies). Used
     * both to start the payment and to show the customer the converted amount beforehand.
     *
     * @return array{0: Money, 1: float|null}|null charge amount and conversion rate (null when not converted)
     */
    public function hostedCharge(Gateway $gateway, HostedPaymentDriver $driver, Money $balance): ?array
    {
        if (in_array($balance->currency, $driver->currencies(), true)) {
            return [$balance, null];
        }

        $target = $gateway->convert_to_currency !== null ? strtoupper($gateway->convert_to_currency) : null;

        if ($target === null || ! in_array($target, $driver->currencies(), true)) {
            return null;
        }

        $rate = $this->converter->rate($balance->currency, $target);

        return [Money::of($this->converter->convert($balance->amount, $rate), $target), $rate];
    }

    /**
     * Why a hosted driver can't take this amount (outside its limits), in words for the customer; null when it can.
     */
    public function limitReason(Gateway $gateway, HostedPaymentDriver $driver, Money $amount): ?string
    {
        $limits = $driver->limits($this->gateways->config($gateway));

        if ($amount->amount < $limits->min) {
            return __(':gateway needs at least :amount.', ['gateway' => $gateway->display_name, 'amount' => $this->formatter->amount($limits->min, $amount->currency)]);
        }

        if ($limits->max !== null && $amount->amount > $limits->max) {
            return __(':gateway takes at most :amount.', ['gateway' => $gateway->display_name, 'amount' => $this->formatter->amount($limits->max, $amount->currency)]);
        }

        return null;
    }

    public function charge(Gateway $gateway, string $token, Money $amount, string $reference, int $clientId, ?int $invoiceId = null): ChargeResult
    {
        $driver = $this->gateways->driver($gateway->key);

        if (! $driver->supports(GatewayDriver::CAPTURE)) {
            throw UnsupportedCapabilityException::for($driver->name(), GatewayDriver::CAPTURE);
        }

        $this->log->log($gateway->key, 'request', 'capture', ['amount' => $amount->amount, 'currency' => $amount->currency, 'reference' => $reference], $invoiceId);
        $config = $this->gateways->config($gateway);
        $result = $this->gateways->during($config, fn (): ChargeResult => $driver->capture($config, new ChargeRequest($amount, $token, __('Payment :reference', ['reference' => $reference]), $reference, $clientId, $invoiceId)));
        $this->log->log($gateway->key, 'response', 'capture '.$result->status, $result->raw, $invoiceId);

        return $result;
    }
}
