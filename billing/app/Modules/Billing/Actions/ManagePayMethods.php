<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Billing\GatewayDriver;
use App\Data\Billing\CardToken;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Services\GatewayCharger;
use Illuminate\Support\Facades\DB;

/**
 * Store, default and remove tokenised pay methods. One default per client.
 */
class ManagePayMethods
{
    public function __construct(
        private GatewayManager $gateways,
        private GatewayCharger $charger,
    ) {}

    public function store(int $clientId, string $gatewayKey, string $token, bool $makeDefault = false): PayMethod
    {
        $gateway = $this->charger->activeGateway($gatewayKey);
        $driver = $this->gateways->driver($gatewayKey);

        if (! $driver->supports(GatewayDriver::TOKENIZE)) {
            throw new BillingException(__('This payment method can\'t be saved.'));
        }

        $config = $this->gateways->config($gateway);
        $details = $this->gateways->during($config, fn (): ?CardToken => $driver->tokenDetails($config, $token)) ?? throw new BillingException(__('The card details could not be verified. Enter them again.'));

        return DB::transaction(function () use ($clientId, $gatewayKey, $details, $makeDefault): PayMethod {
            $isFirst = ! PayMethod::query()->where('client_id', $clientId)->usable()->exists();
            $method = PayMethod::query()->create([
                'client_id' => $clientId,
                'gateway' => $gatewayKey,
                'kind' => 'card',
                'token' => $details->token,
                'brand' => mb_substr($details->brand, 0, 30),
                'last4' => mb_substr($details->last4, 0, 4),
                'expiry_month' => $details->expiryMonth,
                'expiry_year' => $details->expiryYear,
            ]);

            if ($makeDefault || $isFirst) {
                $this->makeDefault($method);
            }

            return $method;
        });
    }

    public function makeDefault(PayMethod $method): void
    {
        DB::transaction(function () use ($method): void {
            PayMethod::query()->where('client_id', $method->client_id)->whereKeyNot($method->id)->update(['is_default' => false]);
            $method->update(['is_default' => true]);
        });
    }

    public function delete(PayMethod $method): void
    {
        DB::transaction(function () use ($method): void {
            $method->delete();

            if ($method->is_default) {
                PayMethod::query()->where('client_id', $method->client_id)->usable()->orderBy('id')->first()?->update(['is_default' => true]);
            }
        });
    }

    /**
     * Pay method used for automatic capture: the client's default, else the first usable one for the gateway.
     */
    /**
     * Pay method for automatic capture. An invoice assigned to a gateway is only ever charged through that gateway
     * (never a bank-transfer invoice): its default method, else its first usable one. Unassigned invoices use the
     * client's default capture method.
     */
    public function forCapture(int $clientId, ?string $gateway): ?PayMethod
    {
        if ($gateway !== null && ! $this->gateways->supports($gateway, GatewayDriver::CAPTURE)) {
            return null;
        }

        $methods = PayMethod::query()->where('client_id', $clientId)->usable()->orderByDesc('is_default')->orderBy('id')->get()
            ->filter(fn (PayMethod $method): bool => $method->isUsable() && $this->gateways->supports($method->gateway, GatewayDriver::CAPTURE))
            ->when($gateway !== null, fn ($methods) => $methods->where('gateway', $gateway));

        return $methods->firstWhere('is_default', true) ?? $methods->first();
    }
}
