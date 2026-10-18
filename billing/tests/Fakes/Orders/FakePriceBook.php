<?php

namespace Tests\Fakes\Orders;

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\ModuleInputs;
use App\Data\Catalog\PriceQuote;
use App\Data\Catalog\ProductSelection;
use App\Data\Catalog\ProductSummary;
use App\Enums\BillingCycle;

/**
 * Configurable in-memory catalog. Prices are minor units in every currency (no conversion).
 */
class FakePriceBook implements PriceBook
{
    /** @var array<int, ProductSummary> */
    public array $products = [];

    /** @var array<int, array<string, array{0: int, 1: int}>> product id => cycle => [recurring, setup] */
    public array $prices = [];

    /** @var array<int, list<array{id: int, name: string, type: string, values: list<array{id: int, name: string, pricing: list<array{cycle: string, recurring: int, setup: int}>}>}>> */
    public array $configOptions = [];

    /** @var array<int, list<array{id: int, name: string, type: string, required: bool, options: list<string>}>> */
    public array $customFields = [];

    /** @var array<int, array<int, string>> product id => option id => module key */
    public array $moduleOptions = [];

    /** @var array<int, list<int>> product id => addon ids */
    public array $productAddons = [];

    /** @var array<int, array{name: string, pricing: array<string, array{0: int, 1: int}>}> */
    public array $addons = [];

    /** @var list<int> */
    public array $reserved = [];

    /** @var list<int> */
    public array $released = [];

    /** @var list<?int> client ids passed to quote() */
    public array $quotedFor = [];

    /**
     * @param  array<string, array{0: int, 1: int}>  $pricing  cycle => [recurring, setup]
     * @param  'order'|'payment'|'accept'|'manual'  $autoSetup
     * @param  list<string>|null  $allowedGateways
     * @param  list<string>  $freeDomainTlds
     * @param  list<string>  $freeDomainCycles
     */
    public function addProduct(
        int $id,
        string $name = 'Starter Hosting',
        array $pricing = ['monthly' => [1000, 0]],
        string $autoSetup = 'payment',
        bool $requiresDomain = false,
        ?int $stock = null,
        bool $taxable = true,
        bool $active = true,
        ?array $allowedGateways = null,
        bool $freeDomain = false,
        array $freeDomainTlds = [],
        array $freeDomainCycles = [],
        bool $freeDomainRecurring = false,
    ): self {
        $this->products[$id] = new ProductSummary(
            $id, $name, 'Hosting', 'shared_hosting', 'demo', null, [], $autoSetup, null, $requiresDomain, $taxable, $stock, $active,
            freeDomain: $freeDomain, freeDomainTlds: $freeDomainTlds, freeDomainCycles: $freeDomainCycles, freeDomainRecurring: $freeDomainRecurring,
            slug: 'product-'.$id, allowedGateways: $allowedGateways,
        );
        $this->prices[$id] = $pricing;

        return $this;
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $pricing
     */
    public function addAddon(int $productId, int $addonId, string $name = 'Backups', array $pricing = ['monthly' => [200, 0]]): self
    {
        $this->addons[$addonId] = ['name' => $name, 'pricing' => $pricing];
        $this->productAddons[$productId][] = $addonId;

        return $this;
    }

    public function setPrice(int $productId, string $cycle, int $recurring, int $setup = 0): self
    {
        $this->prices[$productId][$cycle] = [$recurring, $setup];

        return $this;
    }

    public function product(int $productId): ?ProductSummary
    {
        return $this->products[$productId] ?? null;
    }

    public function quote(ProductSelection $selection): ?PriceQuote
    {
        $this->quotedFor[] = $selection->clientId;
        $price = $this->prices[$selection->productId][$selection->cycle->value] ?? null;

        if ($price === null) {
            return null;
        }

        $recurring = $price[0];

        foreach ($this->configOptions[$selection->productId] ?? [] as $option) {
            foreach ($option['values'] as $value) {
                if (($selection->configOptions[$option['id']] ?? null) === $value['id']) {
                    $recurring += collect($value['pricing'])->firstWhere('cycle', $selection->cycle->value)['recurring'] ?? 0;
                }
            }
        }

        return new PriceQuote($selection->currency, $selection->cycle, $recurring, $price[1]);
    }

    public function addonQuote(int $addonId, BillingCycle $cycle, string $currency): ?PriceQuote
    {
        $price = $this->addons[$addonId]['pricing'][$cycle->value] ?? null;

        return $price === null ? null : new PriceQuote($currency, $cycle, $price[0], $price[1]);
    }

    public function availableCycles(int $productId, string $currency): array
    {
        return array_map(fn (string $cycle): BillingCycle => BillingCycle::from($cycle), array_keys($this->prices[$productId] ?? []));
    }

    public function canUpgrade(int $fromProductId, int $toProductId): bool
    {
        return true;
    }

    public function storefront(string $currency): array
    {
        return [[
            'id' => 1,
            'name' => 'Hosting',
            'slug' => 'hosting',
            'description' => null,
            'products' => array_values(array_map(fn (ProductSummary $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => null,
                'type' => $product->type,
                'requires_domain' => $product->requiresDomain,
                'in_stock' => $product->stock !== 0,
                'pricing' => array_map(fn (string $cycle, array $price): array => ['cycle' => $cycle, 'recurring' => $price[0], 'setup' => $price[1]], array_keys($this->prices[$product->id]), $this->prices[$product->id]),
            ], $this->products)),
        ]];
    }

    public function configOptionsFor(int $productId, string $currency): array
    {
        return $this->configOptions[$productId] ?? [];
    }

    public function addonsFor(int $productId, string $currency): array
    {
        return array_map(fn (int $addonId): array => [
            'id' => $addonId,
            'name' => $this->addons[$addonId]['name'],
            'description' => null,
            'pricing' => array_map(fn (string $cycle, array $price): array => ['cycle' => $cycle, 'recurring' => $price[0], 'setup' => $price[1]], array_keys($this->addons[$addonId]['pricing']), $this->addons[$addonId]['pricing']),
        ], $this->productAddons[$productId] ?? []);
    }

    public function customFieldsFor(int $productId): array
    {
        return $this->customFields[$productId] ?? [];
    }

    /**
     * Custom fields from $customFields (module key, password type); options from $moduleOptions (option id => module
     * key), passing the selected value through.
     */
    public function moduleInputs(int $productId, array $optionSelections, array $customFieldValues): ModuleInputs
    {
        $fields = [];
        $secrets = [];

        foreach ($this->customFields[$productId] ?? [] as $field) {
            if (! array_key_exists($field['id'], $customFieldValues)) {
                continue;
            }

            if ($field['type'] === 'password' && is_scalar($customFieldValues[$field['id']])) {
                $secrets[] = (string) $customFieldValues[$field['id']];
            }

            if (($field['module_key'] ?? '') !== '') {
                $fields[$field['module_key']] = $customFieldValues[$field['id']];
            }
        }

        $options = [];

        foreach ($this->moduleOptions[$productId] ?? [] as $optionId => $moduleKey) {
            if (isset($optionSelections[$optionId])) {
                $options[$moduleKey] = $optionSelections[$optionId];
            }
        }

        return new ModuleInputs($options, $fields, $secrets);
    }

    public function reserveStock(int $productId): bool
    {
        $product = $this->products[$productId] ?? null;

        if ($product === null || $product->stock === null) {
            $this->reserved[] = $productId;

            return true;
        }

        if ($product->stock <= 0) {
            return false;
        }

        $this->setStock($product, $product->stock - 1);
        $this->reserved[] = $productId;

        return true;
    }

    public function releaseStock(int $productId, int $quantity = 1): void
    {
        $product = $this->products[$productId] ?? null;
        $this->released[] = $productId;

        if ($product?->stock !== null) {
            $this->setStock($product, $product->stock + $quantity);
        }
    }

    public function productBySlug(string $slug): ?ProductSummary
    {
        foreach ($this->products as $product) {
            if ($product->slug === $slug) {
                return $product;
            }
        }

        return null;
    }

    private function setStock(ProductSummary $product, int $stock): void
    {
        $this->products[$product->id] = new ProductSummary(...[...get_object_vars($product), 'stock' => $stock]);
    }
}
