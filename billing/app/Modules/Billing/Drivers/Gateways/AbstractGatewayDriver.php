<?php

namespace App\Modules\Billing\Drivers\Gateways;

use App\Modules\Billing\Drivers\Gateways\Data\CallbackEvent;
use App\Modules\Billing\Drivers\Gateways\Data\CardToken;
use App\Modules\Billing\Drivers\Gateways\Data\ChargeRequest;
use App\Modules\Billing\Drivers\Gateways\Data\ChargeResult;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Defaults for drivers that implement only part of the contract.
 */
abstract class AbstractGatewayDriver implements GatewayDriver
{
    public function capabilities(): array
    {
        return [];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function settingsFields(): array
    {
        return [];
    }

    public function instructions(Gateway $gateway, Invoice $invoice): ?string
    {
        return null;
    }

    public function capture(Gateway $gateway, ChargeRequest $request): ChargeResult
    {
        throw UnsupportedCapabilityException::for($this->name(), self::CAPTURE);
    }

    public function refund(Gateway $gateway, Transaction $payment, Money $amount): ChargeResult
    {
        throw UnsupportedCapabilityException::for($this->name(), self::REFUND);
    }

    public function tokenDetails(Gateway $gateway, string $token): ?CardToken
    {
        return null;
    }

    public function parseCallback(Gateway $gateway, Request $request): CallbackEvent
    {
        throw new InvalidCallbackException('This gateway does not accept callbacks.');
    }
}
