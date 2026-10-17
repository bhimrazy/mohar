<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Drivers\Gateways\Data\ChargeRequest;
use App\Modules\Billing\Drivers\Gateways\Data\ChargeResult;
use App\Modules\Billing\Drivers\Gateways\GatewayDriver;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\UnsupportedCapabilityException;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
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
    ) {}

    public function activeGateway(string $key): Gateway
    {
        $gateway = $this->gateways->gateway($key);

        if (! $gateway->exists || ! $gateway->is_active) {
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

    public function charge(Gateway $gateway, string $token, Money $amount, string $reference, int $clientId, ?int $invoiceId = null): ChargeResult
    {
        $driver = $this->gateways->driver($gateway->key);

        if (! $driver->supports(GatewayDriver::CAPTURE)) {
            throw UnsupportedCapabilityException::for($driver->name(), GatewayDriver::CAPTURE);
        }

        $this->log->log($gateway->key, 'request', 'capture', ['amount' => $amount->amount, 'currency' => $amount->currency, 'reference' => $reference], $invoiceId);
        $result = $driver->capture($gateway, new ChargeRequest($amount, $token, __('Payment :reference', ['reference' => $reference]), $reference, $clientId, $invoiceId));
        $this->log->log($gateway->key, 'response', 'capture '.$result->status, $result->raw, $invoiceId);

        return $result;
    }
}
