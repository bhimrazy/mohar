<?php

namespace App\Modules\Orders\Services;

use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Catalog\PriceBook;
use Illuminate\Contracts\Container\Container;

/**
 * Payment gateways a cart may be paid with: Billing's enabled gateways for the currency intersected with
 * every product's allowed gateways (product groups restrict them; catalog.md §2). An empty result blocks
 * checkout (AC 25).
 */
class CheckoutGateways
{
    public function __construct(
        private GatewayDirectory $directory,
        private Container $container,
    ) {}

    /**
     * @return array<string, string> key => label
     */
    public function all(string $currency): array
    {
        $gateways = [];

        foreach ($this->directory->available($currency) as $gateway) {
            $gateways[$gateway['key']] = $gateway['label'];
        }

        return $gateways;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<string, string>
     */
    public function forProducts(array $productIds, string $currency): array
    {
        $gateways = $this->all($currency);
        $priceBook = $productIds === [] ? null : $this->container->make(PriceBook::class);

        foreach ($productIds as $productId) {
            $allowed = $priceBook?->product($productId)?->allowedGateways;

            if ($allowed !== null) {
                $gateways = array_intersect_key($gateways, array_flip($allowed));
            }
        }

        return $gateways;
    }

    public function label(?string $key, string $currency): ?string
    {
        return $key === null ? null : ($this->all($currency)[$key] ?? $key);
    }
}
