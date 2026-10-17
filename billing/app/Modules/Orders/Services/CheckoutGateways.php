<?php

namespace App\Modules\Orders\Services;

use App\Modules\Core\Support\Settings;

/**
 * Payment gateways a cart may be paid with: the order-form gateways intersected with every product's
 * allowed gateways (product groups restrict them; catalog.md §2). An empty result blocks checkout.
 *
 * PriceBook does not expose a product group's allowed gateways yet, so allowedForProduct() returns
 * null (= all) until Catalog provides it; the intersection logic is in place for that.
 */
class CheckoutGateways
{
    public function __construct(private Settings $settings) {}

    /**
     * @return array<string, string> key => label
     */
    public function all(): array
    {
        $gateways = $this->settings->get('orders.gateways', []);

        return is_array($gateways) ? array_map(strval(...), $gateways) : [];
    }

    /**
     * @param  list<int>  $productIds
     * @return array<string, string>
     */
    public function forProducts(array $productIds): array
    {
        $gateways = $this->all();

        foreach ($productIds as $productId) {
            $allowed = $this->allowedForProduct($productId);

            if ($allowed !== null) {
                $gateways = array_intersect_key($gateways, array_flip($allowed));
            }
        }

        return $gateways;
    }

    /**
     * Gateway keys the product's group allows, or null for no restriction.
     *
     * @return list<string>|null
     */
    public function allowedForProduct(int $productId): ?array
    {
        return null;
    }
}
