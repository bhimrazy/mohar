<?php

namespace App\Support\Billing;

use App\Contracts\Billing\GatewayDriver;
use App\Data\Billing\CallbackEvent;
use App\Data\Billing\CallbackRejection;
use App\Data\Billing\CardToken;
use App\Data\Billing\ChargeRequest;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use BadMethodCallException;
use Illuminate\Http\Request;

/**
 * Defaults for gateway drivers that implement only part of the contract. Billing never calls a method whose
 * capability the driver doesn't declare, so the defaults only guard against programming errors.
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

    public function currencies(): ?array
    {
        return null;
    }

    public function settingsFields(): array
    {
        return [];
    }

    public function instructions(GatewayConfig $gateway, string $reference): ?string
    {
        return null;
    }

    public function capture(GatewayConfig $gateway, ChargeRequest $request): ChargeResult
    {
        throw new BadMethodCallException("{$this->key()} does not support capture.");
    }

    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        throw new BadMethodCallException("{$this->key()} does not support refunds.");
    }

    public function tokenDetails(GatewayConfig $gateway, string $token): ?CardToken
    {
        return null;
    }

    public function parseCallback(GatewayConfig $gateway, Request $request): CallbackEvent|CallbackRejection
    {
        return new CallbackRejection('callbacks not supported');
    }
}
